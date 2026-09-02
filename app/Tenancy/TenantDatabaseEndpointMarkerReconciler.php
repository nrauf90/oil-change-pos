<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use Closure;

interface TenantDatabaseEndpointMarkerReconciler
{
    /**
     * @param  array<string, string>  $eligibleSourceMarkerHmacs
     * @param  Closure(TenantDatabaseEndpointMarkerObservation): void  $afterMarkerVerified
     */
    public function reconcile(
        Shop $shop,
        ValidatedTenantConnection $candidate,
        #[\SensitiveParameter]
        array $eligibleSourceMarkerHmacs,
        Closure $afterMarkerVerified,
    ): void;
}
