<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use Illuminate\Cache\DatabaseLock;
use Throwable;

final class DatabaseTenantProvisioningLease implements TenantProvisioningLease
{
    private bool $lost = false;

    public function __construct(
        #[\SensitiveParameter]
        private readonly DatabaseLock $lock,
        private readonly int $seconds,
    ) {}

    public function heartbeat(): void
    {
        if ($this->lost) {
            throw $this->lostException();
        }

        try {
            $owned = $this->lock->refresh($this->seconds)
                && $this->lock->isOwnedByCurrentProcess();
        } catch (Throwable) {
            $owned = false;
        }

        if (! $owned) {
            $this->lost = true;

            throw $this->lostException();
        }
    }

    private function lostException(): TenantProvisioningException
    {
        return TenantProvisioningException::safe(
            'database',
            'PROVISIONING_LOCK_LOST',
            'The provisioning lock was lost. No further changes were made by this worker.',
        );
    }
}
