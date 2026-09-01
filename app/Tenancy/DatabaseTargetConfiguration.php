<?php

namespace App\Tenancy;

use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;

final readonly class DatabaseTargetConfiguration
{
    public function __construct(
        public ?string $driver,
        public ?string $database,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $socket = null,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromLaravelConfiguration(
        #[\SensitiveParameter]
        array $configuration,
    ): self {
        $identity = array_intersect_key($configuration, array_flip([
            'driver',
            'database',
            'host',
            'port',
            'unix_socket',
        ]));
        $url = $configuration['url'] ?? null;

        if ($url) {
            if (! is_string($url)) {
                throw new InvalidArgumentException('The database configuration URL is malformed.');
            }

            $url = preg_replace('#^(sqlite3?):///#', '$1://null/', $url);
            $rawComponents = is_string($url) ? parse_url($url) : false;

            if ($rawComponents === false) {
                throw new InvalidArgumentException('The database configuration URL is malformed.');
            }

            $components = [];

            foreach (['scheme', 'host', 'port', 'path'] as $key) {
                if (array_key_exists($key, $rawComponents)) {
                    $components[$key] = self::parseStringToNativeType(
                        rawurldecode((string) $rawComponents[$key]),
                    );
                }
            }

            $driverAlias = $components['scheme'] ?? null;
            $databasePath = $components['path'] ?? null;
            $primaryOptions = array_filter([
                'driver' => is_string($driverAlias)
                    ? (ConfigurationUrlParser::getDriverAliases()[$driverAlias] ?? $driverAlias)
                    : null,
                'database' => is_string($databasePath) && $databasePath !== '/'
                    ? substr($databasePath, 1)
                    : null,
                'host' => $components['host'] ?? null,
                'port' => $components['port'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
            $queryOptions = [];

            if (isset($rawComponents['query']) && is_string($rawComponents['query'])) {
                $query = [];
                parse_str($rawComponents['query'], $query);

                foreach (['driver', 'database', 'host', 'port', 'unix_socket'] as $key) {
                    if (array_key_exists($key, $query)) {
                        $queryOptions[$key] = self::parseStringToNativeType($query[$key]);
                    }
                }
            }

            $identity = array_merge($identity, $primaryOptions, $queryOptions);
        }

        return new self(
            driver: self::nullableString($identity['driver'] ?? null),
            database: self::nullableString($identity['database'] ?? null),
            host: self::nullableString($identity['host'] ?? null),
            port: self::nullableInteger($identity['port'] ?? null),
            socket: self::nullableString($identity['unix_socket'] ?? null),
        );
    }

    private static function parseStringToNativeType(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::parseStringToNativeType(...), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $parsedValue = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $parsedValue : $value;
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
