<?php

namespace App\Filament\Platform\Resources\Shops\Pages;

use App\Actions\Tenancy\ProvisionShop;
use App\Data\ProvisionShopData;
use App\Filament\Platform\Resources\Shops\ShopResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CreateShop extends CreateRecord
{
    protected static string $resource = ShopResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(#[\SensitiveParameter] array $data): Model
    {
        if (! ShopResource::canCreate()) {
            throw new AuthorizationException('Platform administrator authentication is required.');
        }

        $slug = (string) $data['slug'];
        $driver = (string) config('database.tenant_connection_template.driver', 'sqlite');

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new LogicException('The tenant database driver is not supported.');
        }

        return resolve(ProvisionShop::class)->handle(new ProvisionShopData(
            name: (string) $data['name'],
            slug: $slug,
            databaseDriver: $driver,
            databaseName: $this->databaseName($driver, $slug),
            ownerName: (string) $data['owner_name'],
            ownerUsername: (string) $data['owner_username'],
            ownerEmail: filled($data['owner_email'] ?? null) ? (string) $data['owner_email'] : null,
            temporaryOwnerPassword: (string) $data['temporary_owner_password'],
            initialFeatureKeys: array_values($data['initial_feature_keys'] ?? []),
            timezone: (string) $data['timezone'],
            currency: (string) $data['currency'],
        ));
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Shop provisioned')
            ->body('The shop is active and its owner can sign in.');
    }

    protected function getRedirectUrl(): string
    {
        return ShopResource::getUrl('view', ['record' => $this->record]);
    }

    private function databaseName(string $driver, string $slug): string
    {
        if ($driver === 'sqlite') {
            return rtrim((string) config('database.tenant_sqlite_root'), '/\\')
                .DIRECTORY_SEPARATOR."{$slug}.sqlite";
        }

        return 'tenant_'.str_replace('-', '_', $slug);
    }
}
