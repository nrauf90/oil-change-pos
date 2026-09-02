<?php

namespace App\Tenancy;

use App\Enums\TenantDatabaseEndpointMarkerState;
use App\Exceptions\TenantDatabaseEndpointRotationException;
use App\Models\Central\Shop;
use Closure;
use Illuminate\Database\Connection;

final readonly class ReconcileTenantDatabaseEndpointMarker implements TenantDatabaseEndpointMarkerReconciler
{
    public function __construct(
        private TenantDatabaseEndpointConnectionRunner $connections,
    ) {}

    public function reconcile(
        Shop $shop,
        ValidatedTenantConnection $candidate,
        #[\SensitiveParameter]
        string $oldFingerprint,
        #[\SensitiveParameter]
        string $oldMarkerHmac,
        Closure $afterMarkerVerified,
    ): void {
        $this->connections->run(
            $candidate,
            function (Connection $connection) use (
                $shop,
                $candidate,
                $oldFingerprint,
                $oldMarkerHmac,
                $afterMarkerVerified,
            ): void {
                $marker = $this->lockedMarker($connection);
                $newFingerprint = $candidate->target()->fingerprint;
                $newMarkerHmac = $candidate->expectedMarkerHmac();

                if (! hash_equals((string) $shop->getKey(), $marker['shop_id'])) {
                    throw $this->markerConflict();
                }

                if (hash_equals($oldFingerprint, $marker['target_fingerprint'])
                    && hash_equals($oldMarkerHmac, $marker['attestation_hmac'])) {
                    $state = TenantDatabaseEndpointMarkerState::Old;
                } elseif (hash_equals($newFingerprint, $marker['target_fingerprint'])
                    && hash_equals($newMarkerHmac, $marker['attestation_hmac'])) {
                    $state = TenantDatabaseEndpointMarkerState::New;
                } else {
                    throw $this->markerConflict();
                }

                $afterMarkerVerified($state);

                if ($state === TenantDatabaseEndpointMarkerState::Old) {
                    $updated = $connection->table('tenant_installations')
                        ->where('id', 1)
                        ->where('shop_id', $shop->getKey())
                        ->where('target_fingerprint', $oldFingerprint)
                        ->where('attestation_hmac', $oldMarkerHmac)
                        ->whereNull('connection_nonce')
                        ->update([
                            'target_fingerprint' => $newFingerprint,
                            'attestation_hmac' => $newMarkerHmac,
                            'updated_at' => now(),
                        ]);

                    if ($updated !== 1) {
                        throw $this->markerConflict();
                    }
                }

                $updatedMarker = $this->lockedMarker($connection);

                if (! hash_equals((string) $shop->getKey(), $updatedMarker['shop_id'])
                    || ! hash_equals($newFingerprint, $updatedMarker['target_fingerprint'])
                    || ! hash_equals($newMarkerHmac, $updatedMarker['attestation_hmac'])) {
                    throw $this->markerConflict();
                }
            },
        );
    }

    /**
     * @return array{shop_id: string, target_fingerprint: string, attestation_hmac: string}
     */
    private function lockedMarker(Connection $connection): array
    {
        $markers = $connection->table('tenant_installations')
            ->select(['id', 'shop_id', 'target_fingerprint', 'attestation_hmac', 'connection_nonce'])
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        $marker = $markers->first();

        if ($markers->count() !== 1
            || ! is_object($marker)
            || (int) ($marker->id ?? 0) !== 1
            || ! is_string($marker->shop_id ?? null)
            || ! is_string($marker->target_fingerprint ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $marker->target_fingerprint) !== 1
            || ! is_string($marker->attestation_hmac ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $marker->attestation_hmac) !== 1
            || ($marker->connection_nonce ?? null) !== null) {
            throw $this->markerConflict();
        }

        return [
            'shop_id' => $marker->shop_id,
            'target_fingerprint' => $marker->target_fingerprint,
            'attestation_hmac' => $marker->attestation_hmac,
        ];
    }

    private function markerConflict(): TenantDatabaseEndpointRotationException
    {
        return TenantDatabaseEndpointRotationException::safe(
            'ROTATION_MARKER_CONFLICT',
            'The tenant database marker does not match an eligible rotation state.',
        );
    }
}
