<?php

namespace App\Tenancy;

use App\Exceptions\TenantDatabaseTargetConflict;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class NormalizedDatabaseTarget
{
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    private function __construct(
        public string $driver,
        public string $database,
        public ?string $host,
        public ?int $port,
        public ?string $socket,
        public string $fingerprint,
        public ?string $pendingSqliteFingerprint,
        public bool $hasStableFilesystemIdentity,
        public ?string $locatorFingerprint,
        private array $collisionFingerprints,
    ) {}

    public function collidesWith(self $other): bool
    {
        return array_intersect($this->collisionFingerprints, $other->collisionFingerprints) !== [];
    }

    public static function forTenant(
        DatabaseTargetConfiguration $target,
        DatabaseTargetConfiguration $defaults,
        ?DatabaseTargetConfiguration $central,
        string $sqliteRoot,
        DatabaseHostResolver $hostResolver,
    ): self {
        $normalizedTarget = self::normalize(
            driver: (string) $target->driver,
            database: (string) $target->database,
            host: $target->host ?? $defaults->host,
            port: $target->port ?? $defaults->port,
            socket: $target->socket ?? $defaults->socket,
            allowSqliteMemoryDatabase: false,
            hostResolver: $hostResolver,
            sqliteBasePath: null,
        );
        $centralTarget = self::fromCentralConfiguration($central, $hostResolver);

        if ($centralTarget !== null && $centralTarget->collidesWith($normalizedTarget)) {
            throw TenantDatabaseTargetConflict::centralDatabase();
        }

        if ($normalizedTarget->driver === 'sqlite') {
            self::assertWithinSqliteRoot($normalizedTarget->database, $sqliteRoot);

            return $normalizedTarget;
        }

        if ($normalizedTarget->socket !== null) {
            return $normalizedTarget->withConnectionOverrides(
                host: null,
                port: null,
                socket: $target->socket === null ? null : $normalizedTarget->socket,
            );
        }

        return $normalizedTarget->withConnectionOverrides(
            host: $target->host === null ? null : self::canonicalMySqlHost($target->host),
            port: $target->port === null ? null : self::canonicalMySqlPort($target->port),
            socket: null,
        );
    }

    private function withConnectionOverrides(?string $host, ?int $port, ?string $socket): self
    {
        return new self(
            $this->driver,
            $this->database,
            $host,
            $port,
            $socket,
            $this->fingerprint,
            $this->pendingSqliteFingerprint,
            $this->hasStableFilesystemIdentity,
            $this->locatorFingerprint,
            $this->collisionFingerprints,
        );
    }

    private static function fromCentralConfiguration(
        ?DatabaseTargetConfiguration $configuration,
        DatabaseHostResolver $hostResolver,
    ): ?self {
        if ($configuration === null) {
            return null;
        }

        $driver = $configuration->driver;
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;

        if ($driver === null || ! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return null;
        }

        return self::normalize(
            driver: $driver,
            database: (string) $configuration->database,
            host: $configuration->host,
            port: $configuration->port,
            socket: $configuration->socket,
            allowSqliteMemoryDatabase: true,
            hostResolver: $hostResolver,
            sqliteBasePath: function_exists('base_path') ? base_path() : null,
        );
    }

    private static function normalize(
        string $driver,
        string $database,
        ?string $host,
        ?int $port,
        ?string $socket,
        bool $allowSqliteMemoryDatabase,
        DatabaseHostResolver $hostResolver,
        ?string $sqliteBasePath,
    ): self {
        self::assertSupportedDriver($driver);

        if ($driver === 'sqlite') {
            $database = self::canonicalSqlitePath(
                $database,
                $allowSqliteMemoryDatabase,
                $sqliteBasePath,
            );
            $filesystemIdentity = self::filesystemIdentity($database);
            $pendingFingerprint = self::fingerprint([
                'driver' => $driver,
                'database' => self::pathIdentity($database),
            ]);
            $fingerprint = $filesystemIdentity === null
                ? $pendingFingerprint
                : self::fingerprint(['driver' => $driver, 'file' => $filesystemIdentity]);
            $collisionFingerprints = [$pendingFingerprint];

            if ($fingerprint !== $pendingFingerprint) {
                $collisionFingerprints[] = $fingerprint;
            }

            return new self(
                $driver,
                $database,
                null,
                null,
                null,
                $fingerprint,
                $pendingFingerprint,
                $filesystemIdentity !== null,
                $pendingFingerprint,
                $collisionFingerprints,
            );
        }

        $database = self::canonicalMySqlDatabaseName($database);
        $socket = self::canonicalMySqlSocket($socket);

        if ($socket !== null) {
            $pathFingerprint = self::fingerprint([
                'driver' => $driver,
                'socket' => self::pathIdentity($socket),
                'database' => $database,
            ]);
            $filesystemIdentity = self::filesystemIdentity($socket);
            $fingerprint = $filesystemIdentity === null
                ? $pathFingerprint
                : self::fingerprint([
                    'driver' => $driver,
                    'socket_file' => $filesystemIdentity,
                    'database' => $database,
                ]);
            $collisionFingerprints = [$pathFingerprint];

            if ($fingerprint !== $pathFingerprint) {
                $collisionFingerprints[] = $fingerprint;
            }

            return new self(
                $driver,
                $database,
                null,
                null,
                $socket,
                $fingerprint,
                null,
                false,
                $pathFingerprint,
                $collisionFingerprints,
            );
        }

        $host = self::canonicalMySqlHost($host ?? '127.0.0.1');
        $port = self::canonicalMySqlPort($port ?? 3306);
        $identity = [
            'driver' => $driver,
            'host' => self::mySqlHostIdentity($host, $hostResolver),
            'port' => $port,
            'database' => $database,
        ];

        $fingerprint = self::fingerprint($identity);

        return new self(
            $driver,
            $database,
            $host,
            $port,
            null,
            $fingerprint,
            null,
            false,
            null,
            [$fingerprint],
        );
    }

    private static function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported shop database driver [{$driver}].");
        }
    }

    private static function canonicalMySqlDatabaseName(string $database): string
    {
        $database = trim($database);

        if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/', $database) !== 1) {
            throw new InvalidArgumentException(
                'MySQL database names may contain only 1-64 ASCII letters, numbers, or underscores.',
            );
        }

        return $database;
    }

    private static function canonicalMySqlHost(string $host): string
    {
        $host = Str::lower(trim($host));
        $hasOpeningBracket = str_starts_with($host, '[');
        $hasClosingBracket = str_ends_with($host, ']');

        if ($hasOpeningBracket || $hasClosingBracket) {
            if (! $hasOpeningBracket || ! $hasClosingBracket) {
                throw new InvalidArgumentException(
                    'MySQL tenant database hosts must be canonical host names or IP addresses.',
                );
            }

            $host = substr($host, 1, -1);

            if (@inet_pton($host) === false) {
                throw new InvalidArgumentException(
                    'MySQL tenant database hosts must be canonical host names or IP addresses.',
                );
            }
        } else {
            $host = rtrim($host, '.');
        }

        if ($host === ''
            || preg_match('/[\x00-\x20\x7F]/', $host) === 1
            || str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '\\')
            || str_contains($host, '@')) {
            throw new InvalidArgumentException('MySQL tenant database hosts must be canonical host names or IP addresses.');
        }

        $packedAddress = @inet_pton($host);

        if ($packedAddress === false && ! self::isCanonicalHostName($host)) {
            throw new InvalidArgumentException('MySQL tenant database hosts must be canonical host names or IP addresses.');
        }

        return $packedAddress === false ? $host : (string) inet_ntop($packedAddress);
    }

    private static function isCanonicalHostName(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/\A[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?\z/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function mySqlHostIdentity(
        string $host,
        DatabaseHostResolver $hostResolver,
    ): string {
        if ($host === 'localhost') {
            return 'loopback';
        }

        $packedAddress = @inet_pton($host);

        if ($packedAddress === false) {
            $addresses = array_map(
                self::canonicalIpAddress(...),
                $hostResolver->resolve($host),
            );
            $addresses = array_values(array_unique($addresses));
            sort($addresses, SORT_STRING);

            if ($addresses === []) {
                throw new InvalidArgumentException(
                    'MySQL tenant database hosts must resolve to a stable IP address.',
                );
            }

            return self::mySqlAddressSetIdentity($addresses);
        }

        return self::mySqlAddressSetIdentity([
            self::canonicalPackedIpAddress($packedAddress),
        ]);
    }

    /** @param list<string> $addresses */
    private static function mySqlAddressSetIdentity(array $addresses): string
    {
        return $addresses === ['loopback']
            ? 'loopback'
            : 'addresses:'.implode(',', $addresses);
    }

    private static function canonicalIpAddress(string $address): string
    {
        $packedAddress = @inet_pton($address);

        if ($packedAddress === false) {
            throw new InvalidArgumentException(
                'MySQL tenant database hosts resolved to an invalid IP address.',
            );
        }

        return self::canonicalPackedIpAddress($packedAddress);
    }

    private static function canonicalPackedIpAddress(string $packedAddress): string
    {
        if (strlen($packedAddress) === 16
            && substr($packedAddress, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packedAddress = substr($packedAddress, 12);
        }

        if (strlen($packedAddress) === 4 && ord($packedAddress[0]) === 127) {
            return 'loopback';
        }

        if ($packedAddress === inet_pton('::1')) {
            return 'loopback';
        }

        return (string) inet_ntop($packedAddress);
    }

    private static function canonicalMySqlPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('MySQL tenant database ports must be between 1 and 65535.');
        }

        return $port;
    }

    private static function canonicalMySqlSocket(?string $socket): ?string
    {
        if ($socket === null || trim($socket) === '') {
            return null;
        }

        try {
            return self::canonicalAbsolutePath($socket);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'MySQL tenant database sockets require a canonical absolute file path.',
                previous: $exception,
            );
        }
    }

    private static function canonicalSqlitePath(
        string $database,
        bool $allowMemoryDatabase,
        ?string $basePath,
    ): string {
        if ($allowMemoryDatabase && trim($database) === ':memory:') {
            return ':memory:';
        }

        try {
            if ($basePath !== null && ! self::isAbsoluteLocalPath($database)) {
                $resolvedPath = realpath($database) ?: realpath($basePath.DIRECTORY_SEPARATOR.$database);

                if ($resolvedPath === false) {
                    throw new InvalidArgumentException('The relative database path cannot be resolved.');
                }

                $database = $resolvedPath;
            }

            return self::canonicalAbsolutePath($database);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'SQLite tenant databases require a canonical absolute file path.',
                previous: $exception,
            );
        }
    }

    private static function canonicalAbsolutePath(string $path): string
    {
        $path = trim($path);
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $hasControlCharacter = preg_match('/[\x00-\x1F\x7F]/', $path) === 1;
        $isUncPath = str_starts_with($path, DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR);
        $isAbsolutePath = self::isAbsoluteLocalPath($path);

        if ($path === '' || $hasControlCharacter || $isUncPath || ! $isAbsolutePath) {
            throw new InvalidArgumentException('The database path is not an absolute local file path.');
        }

        $root = DIRECTORY_SEPARATOR === '\\'
            ? Str::upper($path[0]).':'.DIRECTORY_SEPARATOR
            : DIRECTORY_SEPARATOR;
        $relativePath = DIRECTORY_SEPARATOR === '\\'
            ? substr($path, 3)
            : ltrim($path, DIRECTORY_SEPARATOR);
        $segments = [];

        foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException('The database path contains parent traversal.');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidArgumentException('The database path does not identify a file.');
        }

        $canonicalPath = $root.implode(DIRECTORY_SEPARATOR, $segments);

        if (file_exists($canonicalPath) || is_link($canonicalPath)) {
            $resolvedPath = realpath($canonicalPath);

            if ($resolvedPath === false || is_dir($resolvedPath)) {
                throw new InvalidArgumentException('The database path cannot be resolved to a file.');
            }

            return self::normalizeSeparators($resolvedPath);
        }

        $resolvedParent = self::resolveParentPath(dirname($canonicalPath));

        return $resolvedParent.DIRECTORY_SEPARATOR.basename($canonicalPath);
    }

    private static function isAbsoluteLocalPath(string $path): bool
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));

        return DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/\A[A-Za-z]:\\\\/', $path) === 1
            : str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private static function resolveParentPath(string $parent): string
    {
        $missingSegments = [];
        $currentPath = $parent;

        while (! file_exists($currentPath) && ! is_link($currentPath)) {
            $nextPath = dirname($currentPath);

            if ($nextPath === $currentPath) {
                throw new InvalidArgumentException('The database path parent cannot be resolved.');
            }

            array_unshift($missingSegments, basename($currentPath));
            $currentPath = $nextPath;
        }

        $resolvedPath = realpath($currentPath);

        if ($resolvedPath === false || ! is_dir($resolvedPath)) {
            throw new InvalidArgumentException('The database path parent cannot be resolved.');
        }

        $resolvedPath = self::normalizeSeparators($resolvedPath);

        return $missingSegments === []
            ? $resolvedPath
            : $resolvedPath.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $missingSegments);
    }

    private static function assertWithinSqliteRoot(string $database, string $sqliteRoot): void
    {
        $resolvedRoot = realpath($sqliteRoot);

        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException(
                'The configured tenant SQLite database root must be an existing absolute directory.',
            );
        }

        $rootIdentity = rtrim(self::pathIdentity($resolvedRoot), DIRECTORY_SEPARATOR);
        $databaseIdentity = self::pathIdentity($database);

        if (! str_starts_with($databaseIdentity, $rootIdentity.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(
                'SQLite tenant databases must be inside the configured tenant database root.',
            );
        }
    }

    /** @param array<string, int|string> $identity */
    private static function fingerprint(array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function pathIdentity(string $path): string
    {
        $path = self::normalizeSeparators($path);

        return DIRECTORY_SEPARATOR === '\\' ? Str::lower($path) : $path;
    }

    private static function filesystemIdentity(string $path): ?string
    {
        clearstatcache(true, $path);
        $metadata = @stat($path);

        if (! is_array($metadata)
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ($metadata['dev'] === 0 && $metadata['ino'] === 0)) {
            return null;
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }

    private static function normalizeSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
