<?php

namespace App\Tenancy;

use App\Enums\TenantDatabaseEndpointMarkerState;
use App\Models\Central\Shop;
use Closure;

interface TenantDatabaseEndpointMarkerReconciler
{
    /** @param Closure(TenantDatabaseEndpointMarkerState): void $afterMarkerVerified */
    public function reconcile(
        Shop $shop,
        ValidatedTenantConnection $candidate,
        #[\SensitiveParameter]
        string $oldFingerprint,
        #[\SensitiveParameter]
        string $oldMarkerHmac,
        Closure $afterMarkerVerified,
    ): void;
}
