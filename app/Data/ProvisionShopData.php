<?php

namespace App\Data;

use App\Tenancy\TenantSlug;
use InvalidArgumentException;
use LogicException;

final readonly class ProvisionShopData
{
    /**
     * @param  list<string>  $initialFeatureKeys
     */
    public function __construct(
        public string $name,
        public string $slug,
        #[\SensitiveParameter]
        public string $databaseDriver,
        #[\SensitiveParameter]
        public string $databaseName,
        public string $ownerName,
        public string $ownerUsername,
        public ?string $ownerEmail,
        #[\SensitiveParameter]
        public string $temporaryOwnerPassword,
        public array $initialFeatureKeys = [],
        #[\SensitiveParameter]
        public array|string|null $databaseHost = null,
        #[\SensitiveParameter]
        public ?int $databasePort = null,
        #[\SensitiveParameter]
        public ?string $databaseUsername = null,
        #[\SensitiveParameter]
        public ?string $databasePassword = null,
        #[\SensitiveParameter]
        public ?string $databaseSocket = null,
        public string $timezone = 'Asia/Karachi',
        public string $currency = 'PKR',
    ) {
        if (trim($this->name) === '' || mb_strlen($this->name) > 255) {
            throw new InvalidArgumentException('A shop name between 1 and 255 characters is required.');
        }

        if (! TenantSlug::isValid($this->slug)) {
            throw new InvalidArgumentException('The shop slug format is invalid.');
        }

        if (! in_array($this->databaseDriver, ['mysql', 'sqlite'], true)
            || trim($this->databaseName) === '') {
            throw new InvalidArgumentException('The tenant database target is invalid.');
        }

        if (trim($this->ownerName) === '' || mb_strlen($this->ownerName) > 150) {
            throw new InvalidArgumentException('An owner name between 1 and 150 characters is required.');
        }

        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $this->ownerUsername) !== 1
            || mb_strlen($this->ownerUsername) > 50) {
            throw new InvalidArgumentException('The owner username format is invalid.');
        }

        if ($this->ownerEmail !== null
            && (filter_var($this->ownerEmail, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($this->ownerEmail) > 255)) {
            throw new InvalidArgumentException('The owner email format is invalid.');
        }

        if (mb_strlen($this->temporaryOwnerPassword) < 8
            || mb_strlen($this->temporaryOwnerPassword) > 255) {
            throw new InvalidArgumentException('The temporary owner password must contain 8 to 255 characters.');
        }

        if ($this->databasePort !== null && ($this->databasePort < 1 || $this->databasePort > 65535)) {
            throw new InvalidArgumentException('The tenant database port is invalid.');
        }

        if (! in_array($this->timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('The shop timezone is invalid.');
        }

        if (preg_match('/\A[A-Z]{3}\z/', $this->currency) !== 1) {
            throw new InvalidArgumentException('The shop currency is invalid.');
        }

        if (array_values($this->initialFeatureKeys) !== $this->initialFeatureKeys
            || array_unique($this->initialFeatureKeys) !== $this->initialFeatureKeys) {
            throw new InvalidArgumentException('Initial shop features must be a unique list.');
        }

        foreach ($this->initialFeatureKeys as $featureKey) {
            if (! is_string($featureKey)
                || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $featureKey) !== 1) {
                throw new InvalidArgumentException('An initial shop feature key is invalid.');
            }
        }
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Provisioning input cannot be serialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['provisioning_input' => '[redacted]'];
    }
}
