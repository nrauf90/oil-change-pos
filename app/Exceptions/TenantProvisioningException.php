<?php

namespace App\Exceptions;

use RuntimeException;

final class TenantProvisioningException extends RuntimeException
{
    private function __construct(
        public readonly string $stage,
        public readonly string $errorCode,
        string $operatorMessage,
    ) {
        parent::__construct($operatorMessage);
    }

    public static function safe(string $stage, string $errorCode, string $operatorMessage): self
    {
        return new self($stage, $errorCode, $operatorMessage);
    }
}
