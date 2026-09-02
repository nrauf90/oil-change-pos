<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopLifecycleActivity;

class RecordShopLifecycleActivity
{
    /**
     * @param  array{
     *     attempt?: int,
     *     database_driver?: 'mysql'|'sqlite',
     *     migration_batch?: int,
     *     duration_ms?: int,
     *     failure_stage?: string,
     *     error_code?: string,
     *     reason_code?: string,
     *     target_fingerprint?: string,
     *     module_key?: string,
     *     migration?: string,
     *     batch?: int,
     *     table_count?: int,
     *     owner_linked?: bool
     * } $metadata Unknown keys and values outside the event-specific schema are discarded.
     */
    public function handle(
        Shop $shop,
        ShopLifecycleEvent $event,
        ?PlatformUser $actor = null,
        array $metadata = [],
    ): ShopLifecycleActivity {
        $activity = new ShopLifecycleActivity;
        $activity->shop()->associate($shop);

        if ($actor !== null) {
            $activity->platformUser()->associate($actor);
        }

        $safeMetadata = $this->safeMetadata($event, $metadata);
        $activity->forceFill([
            'actor_name' => $actor?->name ?? 'System',
            'event' => $event,
            'metadata' => $safeMetadata === [] ? null : $safeMetadata,
            'occurred_at' => now(),
        ])->save();

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(ShopLifecycleEvent $event, array $metadata): array
    {
        $safeMetadata = [];

        foreach ($this->allowedMetadataKeys($event) as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];

            if ($this->isSafeMetadataValue($key, $value)) {
                $safeMetadata[$key] = $value;
            }
        }

        return $safeMetadata;
    }

    /** @return list<string> */
    private function allowedMetadataKeys(ShopLifecycleEvent $event): array
    {
        return match ($event) {
            ShopLifecycleEvent::ProvisioningStarted => ['attempt', 'database_driver'],
            ShopLifecycleEvent::ProvisioningSucceeded => [
                'attempt', 'database_driver', 'migration_batch', 'duration_ms',
            ],
            ShopLifecycleEvent::ProvisioningFailed => ['attempt', 'failure_stage', 'error_code'],
            ShopLifecycleEvent::TenantInstallationAuthorized => [
                'database_driver', 'reason_code', 'target_fingerprint',
            ],
            ShopLifecycleEvent::Suspended,
            ShopLifecycleEvent::Reactivated => ['reason_code'],
            ShopLifecycleEvent::FeatureEnabled,
            ShopLifecycleEvent::FeatureDisabled => ['module_key', 'reason_code'],
            ShopLifecycleEvent::MigrationSucceeded => ['migration', 'batch', 'duration_ms'],
            ShopLifecycleEvent::MigrationFailed => ['migration', 'batch', 'error_code'],
            ShopLifecycleEvent::ExistingDatabaseAdopted => [
                'database_driver', 'table_count', 'owner_linked',
            ],
        };
    }

    private function isSafeMetadataValue(string $key, mixed $value): bool
    {
        if (in_array($key, ['attempt', 'migration_batch', 'batch', 'duration_ms', 'table_count'], true)) {
            return is_int($value) && $value >= 0;
        }

        if ($key === 'owner_linked') {
            return is_bool($value);
        }

        if ($key === 'database_driver') {
            return is_string($value) && in_array($value, ['mysql', 'sqlite'], true);
        }

        if ($key === 'target_fingerprint') {
            return is_string($value)
                && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
        }

        if (! is_string($value) || $this->looksLikeCredential($value)) {
            return false;
        }

        return match ($key) {
            'error_code' => preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $value) === 1,
            'failure_stage', 'reason_code', 'module_key' => preg_match(
                '/\A[a-z][a-z0-9_-]{0,63}\z/',
                $value,
            ) === 1,
            'migration' => preg_match(
                '/\A(?:all|[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+(?:\.php)?)\z/',
                $value,
            ) === 1,
            default => false,
        };
    }

    private function looksLikeCredential(string $value): bool
    {
        return preg_match(
            '/password|passwd|secret|token|credential|bearer|api[_-]?key'
                .'|\Ask_(?:live|test)_|\AAKIA[0-9A-Z]{12,}|\Agh[pousr]_|\Axox[baprs]-'
                .'|\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){2}\z/i',
            $value,
        ) === 1;
    }
}
