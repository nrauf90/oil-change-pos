<?php

namespace App\Tenancy;

use App\Exceptions\TenantDatabaseTargetConflict;
use Illuminate\Support\ConfigurationUrlParser;
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
    ) {}

    /**
     * @param  array<string, mixed>  $tenantConfiguration
     * @param  array<string, mixed>  $centralConfiguration
     */
    public static function forTenant(
        string $driver,
        string $database,
        ?string $host,
        ?int $port,
        ?string $socket,
        array $tenantConfiguration,
        array $centralConfiguration,
        string $sqliteRoot,
    ): self {
        $target = self::normalize(
            driver: $driver,
            database: $database,
            host: $host ?? self::nullableString($tenantConfiguration['host'] ?? null),
            port: $port ?? self::nullableInteger($tenantConfiguration['port'] ?? null),
            socket: $socket ?? self::nullableString($tenantConfiguration['unix_socket'] ?? null),
            allowSqliteMemoryDatabase: false,
        );
        $centralTarget = self::fromCentralConfiguration($centralConfiguration);

        if ($centralTarget !== null && hash_equals($centralTarget->fingerprint, $target->fingerprint)) {
            throw TenantDatabaseTargetConflict::centralDatabase();
        }

        if ($target->driver === 'sqlite') {
            self::assertWithinSqliteRoot($target->database, $sqliteRoot);
        }

        return $target;
    }

    /** @param array<string, mixed> $configuration */
    private static function fromCentralConfiguration(array $configuration): ?self
    {
        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        $driver = self::nullableString($configuration['driver'] ?? null);
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;

        if ($driver === null || ! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return null;
        }

        return self::normalize(
            driver: $driver,
            database: (string) ($configuration['database'] ?? ''),
            host: self::nullableString($configuration['host'] ?? null),
            port: self::nullableInteger($configuration['port'] ?? null),
            socket: self::nullableString($configuration['unix_socket'] ?? null),
            allowSqliteMemoryDatabase: true,
        );
    }

    private static function normalize(
        string $driver,
        string $database,
        ?string $host,
        ?int $port,
        ?string $socket,
        bool $allowSqliteMemoryDatabase,
    ): self {
        self::assertSupportedDriver($driver);

        if ($driver === 'sqlite') {
            $database = self::canonicalSqlitePath($database, $allowSqliteMemoryDatabase);
            $identity = ['driver' => $driver, 'database' => self::pathIdentity($database)];

            return new self($driver, $database, null, null, null, self::fingerprint($identity));
        }

        $database = self::canonicalMySqlDatabaseName($database);
        $socket = self::canonicalMySqlSocket($socket);

        if ($socket !== null) {
            $identity = [
                'driver' => $driver,
                'socket' => self::pathIdentity($socket),
                'database' => $database,
            ];

            return new self($driver, $database, null, null, $socket, self::fingerprint($identity));
        }

        $host = self::canonicalMySqlHost($host ?? '127.0.0.1');
        $port = self::canonicalMySqlPort($port ?? 3306);
        $identity = ['driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $database];

        return new self($driver, $database, $host, $port, null, self::fingerprint($identity));
    }

    private static function assertSupportedDriver(string $driver): void
    {
        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported shop database driver [{$driver}].");
        }
    }

    private static function canonicalMySqlDatabaseName(string $database): string
    {
        $database = Str::lower(trim($database));

        if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/', $database) !== 1) {
            throw new InvalidArgumentException(
                'MySQL database names may contain only 1-64 ASCII letters, numbers, or underscores.',
            );
        }

        return $database;
    }

    private static function canonicalMySqlHost(string $host): string
    {
        $host = trim($host);
        $host = trim($host, '[]');
        $host = rtrim(Str::lower($host), '.');

        if ($host === ''
            || preg_match('/[\x00-\x20\x7F]/', $host) === 1
            || str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '\\')
            || str_contains($host, '@')) {
            throw new InvalidArgumentException('MySQL tenant database hosts must be canonical host names or IP addresses.');
        }

        return $host;
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

    private static function canonicalSqlitePath(string $database, bool $allowMemoryDatabase): string
    {
        if ($allowMemoryDatabase && trim($database) === ':memory:') {
            return ':memory:';
        }

        try {
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
        $isAbsolutePath = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/\A[A-Za-z]:\\\\/', $path) === 1
            : str_starts_with($path, DIRECTORY_SEPARATOR);

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

    private static function normalizeSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A\d+\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
