<?php

namespace App\Tenancy;

final readonly class TenantConnectionLease
{
    public function __construct()
    {
        $this->nonce = bin2hex(random_bytes(32));
    }

    private string $nonce;

    public function owns(self $lease): bool
    {
        return $this === $lease && hash_equals($this->nonce, $lease->nonce);
    }
}
