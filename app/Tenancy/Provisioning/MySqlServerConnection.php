<?php

namespace App\Tenancy\Provisioning;

interface MySqlServerConnection
{
    public function databaseExists(#[\SensitiveParameter] string $database): bool;

    public function createDatabase(#[\SensitiveParameter] string $database): void;

    public function close(): void;
}
