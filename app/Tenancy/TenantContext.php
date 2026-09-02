<?php

namespace App\Tenancy;

use App\Models\Central\Shop;

final readonly class TenantContext
{
    public function __construct(private TenantRuntimeState $state) {}

    public function initialized(): bool
    {
        return $this->state->initialized();
    }

    public function shop(): Shop
    {
        return $this->state->shop();
    }

    public function id(): string
    {
        return $this->state->id();
    }
}
