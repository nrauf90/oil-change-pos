<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\AdoptExistingDatabase;
use App\Exceptions\TenantProvisioningException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tenants:adopt-existing
    {--name= : Shop display name}
    {--slug= : Immutable shop slug}
    {--owner-username= : Existing active tenant admin username}
    {--owner-name= : Optional central owner display-name override}
    {--owner-email= : Optional central contact email}
    {--force : Commit the central registration; omitted is always dry-run}')]
#[Description('Validate or adopt the database configured as the application default')]
class TenantsAdoptExistingCommand extends Command
{
    public function handle(AdoptExistingDatabase $adoptExistingDatabase): int
    {
        try {
            $result = $adoptExistingDatabase->handle(
                name: $this->stringOption('name'),
                slug: $this->stringOption('slug'),
                ownerUsername: $this->stringOption('owner-username'),
                ownerName: $this->nullableStringOption('owner-name'),
                ownerEmail: $this->nullableStringOption('owner-email'),
                force: (bool) $this->option('force'),
            );
        } catch (TenantProvisioningException $exception) {
            $this->components->error(
                'Existing database adoption failed ['.$this->safeErrorCode($exception).'].',
            );

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('Existing database adoption failed [ADOPTION_FAILED].');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Validated driver=%s tables=%d migrations=%s owner=%s',
            $result['driver'],
            $result['required_table_count'],
            $result['migration_status'],
            $result['owner_username'],
        ));
        $this->components->info(
            $result['adopted']
                ? 'Existing database adopted.'
                : 'Dry run passed; no changes were made.',
        );

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    private function nullableStringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : null;
    }

    private function safeErrorCode(TenantProvisioningException $exception): string
    {
        return preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $exception->errorCode) === 1
            ? $exception->errorCode
            : 'ADOPTION_FAILED';
    }
}
