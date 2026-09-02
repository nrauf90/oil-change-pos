<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use Closure;
use LogicException;

final readonly class TenantSqliteAttestationLock
{
    private string $directory;

    public function __construct(#[\SensitiveParameter] string $directory)
    {
        $directory = rtrim(trim($directory), '/\\');

        if ($directory === '') {
            throw new LogicException('The tenant attestation lock directory is not configured.');
        }

        $this->directory = $directory;
    }

    public function synchronized(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
        #[\SensitiveParameter]
        Closure $operation,
    ): mixed {
        if ($target->driver !== 'sqlite' || $target->locatorFingerprint === null) {
            throw new TenantDatabaseAttestationFailed;
        }

        if (! is_dir($this->directory)
            && ! @mkdir($this->directory, 0700, true)
            && ! is_dir($this->directory)) {
            throw new TenantDatabaseAttestationFailed;
        }

        $lockPath = $this->directory.DIRECTORY_SEPARATOR
            .hash('sha256', $target->locatorFingerprint).'.lock';
        $lock = @fopen($lockPath, 'c+b');

        if ($lock === false) {
            throw new TenantDatabaseAttestationFailed;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new TenantDatabaseAttestationFailed;
            }

            try {
                return $operation();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }
}
