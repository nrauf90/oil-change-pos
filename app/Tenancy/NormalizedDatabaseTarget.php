<?php

namespace App\Tenancy;

use App\Exceptions\TenantDatabaseTargetConflict;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class NormalizedDatabaseTarget
{
    private const SUPPORTED_DRIVERS = ['mysql', 'sqlite'];

    /**
     * @param  list<array{host: string, address: string, port: int}>  $mysqlEndpoints
     * @param  list<string>  $claimFingerprints
     */
    private function __construct(
        #[\SensitiveParameter]
        public string $driver,
        #[\SensitiveParameter]
        public string $database,
        #[\SensitiveParameter]
        public array|string|null $host,
        #[\SensitiveParameter]
        public ?int $port,
        #[\SensitiveParameter]
        public ?string $socket,
        /** @var list<string>|string|null */
        #[\SensitiveParameter]
        public array|string|null $effectiveHost,
        #[\SensitiveParameter]
        public ?int $effectivePort,
        #[\SensitiveParameter]
        public ?string $effectiveSocket,
        public string $fingerprint,
        public ?string $pendingSqliteFingerprint,
        public bool $hasStableFilesystemIdentity,
        public ?string $locatorFingerprint,
        public ?string $filesystemIdentity,
        public array $mysqlEndpoints,
        private array $claimFingerprints,
    ) {}

    public function collidesWith(#[\SensitiveParameter] self $other): bool
    {
        return array_intersect($this->claimFingerprints, $other->claimFingerprints) !== [];
    }

    /** @return list<string> */
    public function claimFingerprints(): array
    {
        return $this->claimFingerprints;
    }

    public function hasCurrentMySqlEndpoints(
        #[\SensitiveParameter]
        DatabaseHostResolver $hostResolver,
    ): bool {
        if ($this->driver !== 'mysql') {
            return false;
        }

        if ($this->effectiveSocket !== null) {
            return true;
        }

        $currentTarget = self::normalize(
            driver: $this->driver,
            database: $this->database,
            host: $this->effectiveHost,
            port: $this->effectivePort,
            socket: null,
            allowSqliteMemoryDatabase: false,
            hostResolver: $hostResolver,
            sqliteBasePath: null,
        );

        return $currentTarget->mysqlEndpoints === $this->mysqlEndpoints;
    }

    public static function forTenant(
        #[\SensitiveParameter]
        DatabaseTargetConfiguration $target,
        #[\SensitiveParameter]
        DatabaseTargetConfiguration $defaults,
        #[\SensitiveParameter]
        ?DatabaseTargetConfiguration $central,
        #[\SensitiveParameter]
        string $sqliteRoot,
        #[\SensitiveParameter]
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
            host: $target->host === null ? null : self::canonicalMySqlHostConfiguration($target->host),
            port: $target->port === null ? null : self::canonicalMySqlPort($target->port),
            socket: null,
        );
    }

    /** @param list<string>|string|null $host */
    private function withConnectionOverrides(
        #[\SensitiveParameter]
        array|string|null $host,
        #[\SensitiveParameter]
        ?int $port,
        #[\SensitiveParameter]
        ?string $socket,
    ): self {
        return new self(
            $this->driver,
            $this->database,
            $host,
            $port,
            $socket,
            $this->effectiveHost,
            $this->effectivePort,
            $this->effectiveSocket,
            $this->fingerprint,
            $this->pendingSqliteFingerprint,
            $this->hasStableFilesystemIdentity,
            $this->locatorFingerprint,
            $this->filesystemIdentity,
            $this->mysqlEndpoints,
            $this->claimFingerprints,
        );
    }

    private static function fromCentralConfiguration(
        #[\SensitiveParameter]
        ?DatabaseTargetConfiguration $configuration,
        #[\SensitiveParameter]
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
        #[\SensitiveParameter]
        string $driver,
        #[\SensitiveParameter]
        string $database,
        #[\SensitiveParameter]
        array|string|null $host,
        #[\SensitiveParameter]
        ?int $port,
        #[\SensitiveParameter]
        ?string $socket,
        bool $allowSqliteMemoryDatabase,
        #[\SensitiveParameter]
        DatabaseHostResolver $hostResolver,
        #[\SensitiveParameter]
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
            $claimFingerprints = [$pendingFingerprint];

            if ($fingerprint !== $pendingFingerprint) {
                $claimFingerprints[] = $fingerprint;
            }

            return new self(
                $driver,
                $database,
                null,
                null,
                null,
                null,
                null,
                null,
                $fingerprint,
                $pendingFingerprint,
                $filesystemIdentity !== null,
                $pendingFingerprint,
                $filesystemIdentity,
                [],
                $claimFingerprints,
            );
        }

        $database = self::canonicalMySqlDatabaseName($database);
        $socket = self::canonicalMySqlSocket($socket);

        if ($socket !== null) {
            $pathFingerprint = self::fingerprint([
                'driver' => $driver,
                'socket' => self::pathIdentity($socket),
                'database' => self::mySqlSchemaIdentity($database),
            ]);

            return new self(
                $driver,
                $database,
                null,
                null,
                $socket,
                null,
                null,
                $socket,
                $pathFingerprint,
                null,
                false,
                $pathFingerprint,
                null,
                [],
                [$pathFingerprint],
            );
        }

        $host = self::canonicalMySqlHostConfiguration($host ?? '127.0.0.1');
        $port = self::canonicalMySqlPort($port ?? 3306);
        $hosts = is_array($host) ? $host : [$host];
        $schemaIdentity = self::mySqlSchemaIdentity($database);
        $claimFingerprints = [];
        $mysqlEndpoints = [];

        foreach ($hosts as $configuredHost) {
            foreach (self::resolveMySqlHostEndpoints($configuredHost, $hostResolver) as $endpoint) {
                $claimFingerprints[] = self::fingerprint([
                    'driver' => $driver,
                    'endpoint' => $endpoint['identity'],
                    'port' => $port,
                    'database' => $schemaIdentity,
                ]);
                $mysqlEndpoints[] = [
                    'host' => $configuredHost,
                    'address' => $endpoint['address'],
                    'port' => $port,
                ];
            }
        }

        $claimFingerprints = array_values(array_unique($claimFingerprints));
        sort($claimFingerprints, SORT_STRING);
        $mysqlEndpoints = self::uniqueMySqlEndpoints($mysqlEndpoints);
        $fingerprint = self::fingerprint([
            'driver' => $driver,
            'claims' => implode(',', $claimFingerprints),
        ]);

        return new self(
            $driver,
            $database,
            $host,
            $port,
            null,
            $host,
            $port,
            null,
            $fingerprint,
            null,
            false,
            null,
            null,
            $mysqlEndpoints,
            $claimFingerprints,
        );
    }

    private static function assertSupportedDriver(#[\SensitiveParameter] string $driver): void
    {
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported shop database driver [{$driver}].");
        }
    }

    private static function canonicalMySqlDatabaseName(#[\SensitiveParameter] string $database): string
    {
        $database = trim($database);

        if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/', $database) !== 1) {
            throw new InvalidArgumentException(
                'MySQL database names may contain only 1-64 ASCII letters, numbers, or underscores.',
            );
        }

        return $database;
    }

    private static function mySqlSchemaIdentity(#[\SensitiveParameter] string $database): string
    {
        return Str::lower($database);
    }

    /**
     * @param  list<string>|string  $host
     * @return list<string>|string
     */
    private static function canonicalMySqlHostConfiguration(
        #[\SensitiveParameter]
        array|string $host,
    ): array|string {
        if (is_string($host)) {
            return self::canonicalMySqlHost($host);
        }

        if ($host === []) {
            throw new InvalidArgumentException('MySQL tenant database host arrays cannot be empty.');
        }

        $hosts = [];

        foreach ($host as $configuredHost) {
            if (! is_string($configuredHost)) {
                throw new InvalidArgumentException(
                    'MySQL tenant database host arrays must contain only host names or IP addresses.',
                );
            }

            $hosts[] = self::canonicalMySqlHost($configuredHost);
        }

        return array_values(array_unique($hosts));
    }

    private static function canonicalMySqlHost(#[\SensitiveParameter] string $host): string
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

    private static function isCanonicalHostName(#[\SensitiveParameter] string $host): bool
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

    /** @return list<array{address: string, identity: string}> */
    private static function resolveMySqlHostEndpoints(
        #[\SensitiveParameter]
        string $host,
        #[\SensitiveParameter]
        DatabaseHostResolver $hostResolver,
    ): array {
        if ($host === 'localhost') {
            return [[
                'address' => '127.0.0.1',
                'identity' => 'loopback',
            ]];
        }

        $packedAddress = @inet_pton($host);

        if ($packedAddress === false) {
            $endpoints = array_map(
                self::canonicalIpEndpoint(...),
                $hostResolver->resolve($host),
            );
            $endpoints = self::uniqueResolvedEndpoints($endpoints);

            if ($endpoints === []) {
                throw new InvalidArgumentException(
                    'MySQL tenant database hosts must resolve to a stable IP address.',
                );
            }

            return $endpoints;
        }

        return [self::canonicalPackedIpEndpoint($packedAddress)];
    }

    /**
     * @param  list<array{address: string, identity: string}>  $endpoints
     * @return list<array{address: string, identity: string}>
     */
    private static function uniqueResolvedEndpoints(#[\SensitiveParameter] array $endpoints): array
    {
        $uniqueEndpoints = [];

        foreach ($endpoints as $endpoint) {
            $uniqueEndpoints[$endpoint['address'].'|'.$endpoint['identity']] = $endpoint;
        }

        ksort($uniqueEndpoints, SORT_STRING);

        return array_values($uniqueEndpoints);
    }

    /** @return array{address: string, identity: string} */
    private static function canonicalIpEndpoint(#[\SensitiveParameter] string $address): array
    {
        $packedAddress = @inet_pton($address);

        if ($packedAddress === false) {
            throw new InvalidArgumentException(
                'MySQL tenant database hosts resolved to an invalid IP address.',
            );
        }

        return self::canonicalPackedIpEndpoint($packedAddress);
    }

    /** @return array{address: string, identity: string} */
    private static function canonicalPackedIpEndpoint(#[\SensitiveParameter] string $packedAddress): array
    {
        if (strlen($packedAddress) === 16
            && substr($packedAddress, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packedAddress = substr($packedAddress, 12);
        }

        $address = (string) inet_ntop($packedAddress);

        if (strlen($packedAddress) === 4 && ord($packedAddress[0]) === 127) {
            return ['address' => $address, 'identity' => 'loopback'];
        }

        if ($packedAddress === inet_pton('::1')) {
            return ['address' => $address, 'identity' => 'loopback'];
        }

        return ['address' => $address, 'identity' => $address];
    }

    /**
     * @param  list<array{host: string, address: string, port: int}>  $endpoints
     * @return list<array{host: string, address: string, port: int}>
     */
    private static function uniqueMySqlEndpoints(#[\SensitiveParameter] array $endpoints): array
    {
        $uniqueEndpoints = [];

        foreach ($endpoints as $endpoint) {
            $key = $endpoint['host'].'|'.$endpoint['address'].'|'.$endpoint['port'];
            $uniqueEndpoints[$key] = $endpoint;
        }

        ksort($uniqueEndpoints, SORT_STRING);

        return array_values($uniqueEndpoints);
    }

    private static function canonicalMySqlPort(#[\SensitiveParameter] int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('MySQL tenant database ports must be between 1 and 65535.');
        }

        return $port;
    }

    private static function canonicalMySqlSocket(#[\SensitiveParameter] ?string $socket): ?string
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
        #[\SensitiveParameter]
        string $database,
        bool $allowMemoryDatabase,
        #[\SensitiveParameter]
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

    private static function canonicalAbsolutePath(#[\SensitiveParameter] string $path): string
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

    private static function isAbsoluteLocalPath(#[\SensitiveParameter] string $path): bool
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));

        return DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/\A[A-Za-z]:\\\\/', $path) === 1
            : str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private static function resolveParentPath(#[\SensitiveParameter] string $parent): string
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

    private static function assertWithinSqliteRoot(
        #[\SensitiveParameter]
        string $database,
        #[\SensitiveParameter]
        string $sqliteRoot,
    ): void {
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
    private static function fingerprint(#[\SensitiveParameter] array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function pathIdentity(#[\SensitiveParameter] string $path): string
    {
        $path = self::normalizeSeparators($path);

        return DIRECTORY_SEPARATOR === '\\' ? Str::lower($path) : $path;
    }

    private static function filesystemIdentity(#[\SensitiveParameter] string $path): ?string
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

    private static function normalizeSeparators(#[\SensitiveParameter] string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
