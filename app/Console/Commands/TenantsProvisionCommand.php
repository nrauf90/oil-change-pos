<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\ProvisionShop;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('tenants:provision
    {shop : Central shop UUID or exact slug}
    {--owner-password-file= : Secret file used only if the tenant owner is still absent}
    {--force : Run without the production confirmation}')]
#[Description('Resume provisioning for one registered shop')]
class TenantsProvisionCommand extends Command
{
    use ConfirmableTrait;

    public function handle(ProvisionShop $provisionShop): int
    {
        if (! $this->canProceed()) {
            return self::FAILURE;
        }

        $selector = $this->argument('shop');

        if (! is_string($selector) || $selector === '') {
            $this->components->error('A shop selector is required.');

            return self::INVALID;
        }

        $shops = Shop::query()
            ->where(static function (Builder $query) use ($selector): void {
                $query->whereKey($selector)->orWhere('slug', $selector);
            })
            ->limit(2)
            ->get();
        $shop = $shops->count() === 1 ? $shops->first() : null;

        if (! $shop instanceof Shop) {
            $this->components->error('The requested shop was not found.');

            return self::INVALID;
        }

        $wasAlreadyActive = $shop->status === ShopStatus::Active && $shop->provisioned_at !== null;

        try {
            $password = $wasAlreadyActive ? null : $this->readOwnerPassword();
            $provisionShop->retry($shop, $password);
        } catch (TenantProvisioningException $exception) {
            $this->components->error('Shop provisioning failed ['.$exception->errorCode.'].');

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('Shop provisioning failed [PROVISIONING_FAILED].');

            return self::FAILURE;
        } finally {
            if (isset($password)) {
                $password = null;
            }
        }

        $this->components->info(
            $wasAlreadyActive
                ? 'Shop is already active; no provisioning work was required.'
                : 'Shop provisioning completed.',
        );

        return self::SUCCESS;
    }

    private function canProceed(): bool
    {
        if ($this->laravel->environment() === 'production'
            && ! $this->option('force')
            && ! $this->input->isInteractive()) {
            $this->components->error('Use --force for non-interactive production provisioning.');

            return false;
        }

        return $this->confirmToProceed('Tenant provisioning in production');
    }

    private function readOwnerPassword(): ?string
    {
        $passwordFile = $this->option('owner-password-file');

        if ($passwordFile === null) {
            return null;
        }

        if (! is_string($passwordFile)
            || $passwordFile === ''
            || is_link($passwordFile)
            || ! is_file($passwordFile)
            || ! is_readable($passwordFile)) {
            throw TenantProvisioningException::safe(
                'owner',
                'OWNER_PASSWORD_FILE_INVALID',
                'The owner password file is invalid.',
            );
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $permissions = fileperms($passwordFile);

            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw TenantProvisioningException::safe(
                    'owner',
                    'OWNER_PASSWORD_FILE_PERMISSIONS',
                    'The owner password file permissions are too broad.',
                );
            }
        }

        $size = filesize($passwordFile);

        if (! is_int($size) || $size < 1 || $size > 4096) {
            throw TenantProvisioningException::safe(
                'owner',
                'OWNER_PASSWORD_FILE_INVALID',
                'The owner password file is invalid.',
            );
        }

        $password = file_get_contents($passwordFile);

        if (! is_string($password) || str_contains($password, "\0")) {
            throw TenantProvisioningException::safe(
                'owner',
                'OWNER_PASSWORD_FILE_INVALID',
                'The owner password file is invalid.',
            );
        }

        $password = preg_replace('/\r?\n\z/', '', $password);

        if (! is_string($password)
            || mb_strlen($password) < 8
            || mb_strlen($password) > 255) {
            throw TenantProvisioningException::safe(
                'owner',
                'OWNER_PASSWORD_FILE_INVALID',
                'The owner password file is invalid.',
            );
        }

        return $password;
    }
}
