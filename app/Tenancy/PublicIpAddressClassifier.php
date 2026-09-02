<?php

namespace App\Tenancy;

use LogicException;

final readonly class PublicIpAddressClassifier
{
    /** @var list<array{string, int}> */
    private const FORBIDDEN_IPV6_PREFIXES = [
        ['::', 96],
        ['::ffff:0:0', 96],
        ['::ffff:0:0:0', 96],
        ['64:ff9b::', 96],
        ['64:ff9b:1::', 48],
        ['100::', 64],
        ['100:0:0:1::', 64],
        ['2001::', 32],
        ['2001:2::', 48],
        ['2001:10::', 28],
        ['2001:20::', 28],
        ['2001:db8::', 32],
        ['2002::', 16],
        ['3fff::', 20],
        ['5f00::', 16],
        ['fc00::', 7],
        ['fe80::', 10],
        ['fec0::', 10],
        ['ff00::', 8],
    ];

    public function isPublicUnicast(#[\SensitiveParameter] string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE,
        ) === false) {
            return false;
        }

        $packedAddress = @inet_pton($address);

        if (! is_string($packedAddress)) {
            return false;
        }

        if (strlen($packedAddress) === 4) {
            return ord($packedAddress[0]) < 224;
        }

        if (strlen($packedAddress) !== 16) {
            return false;
        }

        foreach (self::FORBIDDEN_IPV6_PREFIXES as [$prefix, $prefixLength]) {
            if ($this->matchesPrefix($packedAddress, $prefix, $prefixLength)) {
                return false;
            }
        }

        return ! in_array(
            bin2hex(substr($packedAddress, 8, 4)),
            ['00005efe', '02005efe'],
            true,
        );
    }

    private function matchesPrefix(
        #[\SensitiveParameter]
        string $packedAddress,
        string $prefix,
        int $prefixLength,
    ): bool {
        $packedPrefix = inet_pton($prefix);

        if (! is_string($packedPrefix)) {
            throw new LogicException('The public IP classifier contains an invalid prefix.');
        }

        $wholeBytes = intdiv($prefixLength, 8);

        if (substr($packedAddress, 0, $wholeBytes) !== substr($packedPrefix, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedPrefix[$wholeBytes]) & $mask);
    }
}
