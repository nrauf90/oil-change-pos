<?php

namespace App\Tenancy;

final readonly class ValidatedTenantConnection
{
    /** @param array<string, mixed> $connectionOverrides */
    public function __construct(
        #[\SensitiveParameter]
        private array $connectionOverrides,
        #[\SensitiveParameter]
        private NormalizedDatabaseTarget $target,
        #[\SensitiveParameter]
        private string $expectedMarkerHmac,
    ) {}

    /** @return array<string, mixed> */
    public function connectionOverrides(): array
    {
        return $this->connectionOverrides;
    }

    public function target(): NormalizedDatabaseTarget
    {
        return $this->target;
    }

    public function expectedMarkerHmac(): string
    {
        return $this->expectedMarkerHmac;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['connection' => '[redacted]'];
    }
}
