<?php

namespace App\Tenancy\Migrations;

final readonly class TenantMigrationResult
{
    /**
     * @param  list<string>  $migrations
     */
    public function __construct(
        public array $migrations,
        public int $batch,
        public int $durationMs,
    ) {}
}
