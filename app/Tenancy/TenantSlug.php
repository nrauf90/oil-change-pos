<?php

namespace App\Tenancy;

use InvalidArgumentException;

final readonly class TenantSlug
{
    public const int MAX_LENGTH = 63;

    public const string PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public function __construct(public string $value)
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException('The shop slug format is invalid.');
        }
    }

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }
}
