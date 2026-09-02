<?php

namespace App\Tenancy;

use App\Enums\TenantDatabaseEndpointMarkerState;

final readonly class TenantDatabaseEndpointMarkerObservation
{
    public function __construct(
        public TenantDatabaseEndpointMarkerState $state,
        public string $fingerprint,
    ) {}
}
