<?php

namespace App\Tenancy;

use InvalidArgumentException;

final readonly class SqliteDatabaseIdentitySnapshot
{
    private function __construct(
        public string $database,
        public string $filesystemIdentity,
    ) {}

    public static function capture(string $database, mixed $exclusiveFileHandle): self
    {
        if (! is_resource($exclusiveFileHandle)) {
            throw new InvalidArgumentException('SQLite identity capture requires an open exclusive file handle.');
        }

        $streamMetadata = stream_get_meta_data($exclusiveFileHandle);

        if (! str_starts_with((string) ($streamMetadata['mode'] ?? ''), 'x')) {
            throw new InvalidArgumentException('SQLite identity capture requires an exclusively created file handle.');
        }

        $filesystemMetadata = fstat($exclusiveFileHandle);

        if (! is_array($filesystemMetadata)
            || ! is_int($filesystemMetadata['dev'] ?? null)
            || ! is_int($filesystemMetadata['ino'] ?? null)
            || ($filesystemMetadata['dev'] === 0 && $filesystemMetadata['ino'] === 0)) {
            throw new InvalidArgumentException('The SQLite file handle has no stable filesystem identity.');
        }

        return new self(
            database: $database,
            filesystemIdentity: $filesystemMetadata['dev'].':'.$filesystemMetadata['ino'],
        );
    }

    public function matchesDatabase(string $database): bool
    {
        $expected = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->database);
        $actual = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $database);

        if (DIRECTORY_SEPARATOR === '\\') {
            return mb_strtolower($expected) === mb_strtolower($actual);
        }

        return $expected === $actual;
    }
}
