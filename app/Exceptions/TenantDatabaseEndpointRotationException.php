<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantDatabaseEndpointRotationException extends DomainException implements ShouldntReport
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function safe(string $errorCode, string $message): self
    {
        return new self($errorCode, $message);
    }
}
