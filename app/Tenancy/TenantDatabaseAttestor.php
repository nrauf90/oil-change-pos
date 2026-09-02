<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use PDO;

final readonly class TenantDatabaseAttestor
{
    public function __construct(
        private ConfigRepository $config,
        private TenantSqliteAttestationLock $sqliteAttestationLock,
    ) {}

    /** @param array<string, mixed> $expectedConfiguration */
    public function attest(
        Connection $connection,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
        #[\SensitiveParameter]
        array $expectedConfiguration,
    ): PDO {
        $this->assertConnectionConfiguration($connection, $expectedConfiguration);
        $target = $snapshot->target();
        resolve(TenantConnectionAttestationHook::class)->beforeOpen($target);

        if ($target->driver === 'mysql'
            && ! $target->hasCurrentMySqlEndpoints(resolve(DatabaseHostResolver::class))) {
            throw new TenantDatabaseAttestationFailed;
        }

        $pdo = $connection->getPdo();
        resolve(TenantConnectionAttestationHook::class)->afterOpen($pdo, $target);

        if ($target->driver === 'sqlite') {
            $this->attestSqliteConnection($pdo, $target);
        } elseif ($target->driver === 'mysql') {
            $this->attestMySqlConnection($pdo, $target);
        } else {
            throw new TenantDatabaseAttestationFailed;
        }

        $this->attestTenantMarker($pdo, $shop, $snapshot);

        if ($target->driver === 'sqlite') {
            $this->sqliteAttestationLock->synchronized(
                $target,
                fn (): mixed => $this->attestSqliteOpenedFile($pdo, $target),
            );
        }

        return $pdo;
    }

    /** @param array<string, mixed> $expected */
    private function assertConnectionConfiguration(
        Connection $connection,
        #[\SensitiveParameter]
        array $expected,
    ): void {
        $configured = $this->config->get('database.connections.tenant');

        if (! is_array($configured)) {
            throw new TenantDatabaseAttestationFailed;
        }

        $connectionConfiguration = $connection->getConfig();

        if (! is_array($connectionConfiguration)) {
            throw new TenantDatabaseAttestationFailed;
        }

        unset($connectionConfiguration['name']);

        if ($configured !== $expected
            || $this->withoutCredentials($connectionConfiguration) !== $this->withoutCredentials($expected)) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    /** @param array<string, mixed> $configuration */
    private function withoutCredentials(#[\SensitiveParameter] array $configuration): array
    {
        unset($configuration['username'], $configuration['password'], $configuration['url']);

        return $configuration;
    }

    private function attestSqliteConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        if ($target->filesystemIdentity === null || ! $target->hasStableFilesystemIdentity) {
            throw new TenantDatabaseAttestationFailed;
        }

        $statement = $pdo->query('PRAGMA database_list');
        $databases = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $mainDatabase = null;

        foreach ($databases as $database) {
            if (($database['name'] ?? null) === 'main' && is_string($database['file'] ?? null)) {
                $mainDatabase = $database['file'];
                break;
            }
        }

        clearstatcache(true, $target->database);
        $canonicalDatabase = is_string($mainDatabase) ? realpath($mainDatabase) : false;
        $expectedDatabase = realpath($target->database);

        if ($canonicalDatabase === false
            || $expectedDatabase === false
            || ! hash_equals($expectedDatabase, $canonicalDatabase)
            || ! hash_equals($target->filesystemIdentity, $this->filesystemIdentity($canonicalDatabase))) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestMySqlConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $statement = $pdo->query('SELECT DATABASE()');
        $database = $statement === false ? false : $statement->fetchColumn();

        if (! is_string($database) || ! hash_equals($target->database, $database)) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestTenantMarker(
        PDO $pdo,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
    ): void {
        $statement = $pdo->prepare(
            'SELECT shop_id, target_fingerprint, attestation_hmac FROM tenant_installations WHERE id = ? LIMIT 2',
        );
        $statement->execute([1]);
        $markers = $statement->fetchAll(PDO::FETCH_ASSOC);
        $target = $snapshot->target();

        if (count($markers) !== 1
            || ! is_string($markers[0]['shop_id'] ?? null)
            || ! is_string($markers[0]['target_fingerprint'] ?? null)
            || ! is_string($markers[0]['attestation_hmac'] ?? null)
            || ! hash_equals((string) $shop->getKey(), $markers[0]['shop_id'])
            || ! hash_equals($target->fingerprint, $markers[0]['target_fingerprint'])
            || ! hash_equals($snapshot->expectedMarkerHmac(), $markers[0]['attestation_hmac'])) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestSqliteOpenedFile(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $nonce = bin2hex(random_bytes(32));
        $statement = $pdo->prepare('UPDATE tenant_installations SET connection_nonce = ? WHERE id = ?');
        $statement->execute([$nonce, 1]);
        $statement = null;
        $witness = null;
        resolve(TenantConnectionAttestationHook::class)->afterSqliteNonceWritten($pdo, $target);

        try {
            clearstatcache(true, $target->database);
            $witness = new PDO('sqlite:'.$target->database, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $observedNonce = $witness
                ->query('SELECT connection_nonce FROM tenant_installations WHERE id = 1')
                ?->fetchColumn();

            if (! is_string($observedNonce) || ! hash_equals($nonce, $observedNonce)) {
                throw new TenantDatabaseAttestationFailed;
            }
        } finally {
            $witness = null;
            $clearNonce = $pdo->prepare('UPDATE tenant_installations SET connection_nonce = NULL WHERE id = ?');
            $clearNonce->execute([1]);
        }
    }

    private function filesystemIdentity(#[\SensitiveParameter] string $database): string
    {
        clearstatcache(true, $database);
        $metadata = @stat($database);

        if (! is_array($metadata)
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ($metadata['dev'] === 0 && $metadata['ino'] === 0)) {
            throw new TenantDatabaseAttestationFailed;
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }
}
