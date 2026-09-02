<?php

namespace Tests\Unit\Tenancy;

use App\Tenancy\PublicIpAddressClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicIpAddressClassifierTest extends TestCase
{
    #[DataProvider('nonPublicAddresses')]
    public function test_rejects_non_public_and_embedded_addresses(string $address): void
    {
        $this->assertFalse((new PublicIpAddressClassifier)->isPublicUnicast($address));
    }

    #[DataProvider('publicAddresses')]
    public function test_accepts_ordinary_public_unicast_addresses(string $address): void
    {
        $this->assertTrue((new PublicIpAddressClassifier)->isPublicUnicast($address));
    }

    /** @return array<string, array{string}> */
    public static function nonPublicAddresses(): array
    {
        return [
            'empty input' => [''],
            'whitespace input' => [' 8.8.8.8 '],
            'host name' => ['database.example.test'],
            'bracketed IPv6' => ['[2606:4700:4700::1111]'],
            'IPv6 zone identifier' => ['fe80::1%eth0'],
            'IPv4 this network' => ['0.0.0.1'],
            'IPv4 private' => ['10.20.30.40'],
            'IPv4 carrier-grade NAT' => ['100.64.0.1'],
            'IPv4 loopback' => ['127.0.0.2'],
            'IPv4 link-local' => ['169.254.20.30'],
            'IPv4 IETF protocol assignment' => ['192.0.0.8'],
            'IPv4 documentation TEST-NET-1' => ['192.0.2.1'],
            'IPv4 private class B' => ['172.20.30.40'],
            'IPv4 private class C' => ['192.168.30.40'],
            'IPv4 benchmarking' => ['198.18.0.1'],
            'IPv4 documentation TEST-NET-2' => ['198.51.100.1'],
            'IPv4 documentation TEST-NET-3' => ['203.0.113.1'],
            'IPv4 multicast' => ['224.0.0.1'],
            'IPv4 reserved future use' => ['240.0.0.1'],
            'IPv4 limited broadcast' => ['255.255.255.255'],
            'IPv6 unspecified' => ['::'],
            'IPv6 loopback' => ['::1'],
            'IPv4-compatible IPv6' => ['::808:808'],
            'IPv4-mapped public IPv6' => ['::ffff:8.8.8.8'],
            'IPv4-translatable IPv6' => ['::ffff:0:7f00:1'],
            'NAT64 well-known private embedding' => ['64:ff9b::7f00:1'],
            'NAT64 well-known public embedding' => ['64:ff9b::808:808'],
            'NAT64 local-use private embedding' => ['64:ff9b:1::7f00:1'],
            'NAT64 local-use public embedding' => ['64:ff9b:1::808:808'],
            'IPv6 discard-only' => ['100::1'],
            'IPv6 dummy prefix' => ['100:0:0:1::1'],
            'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'IPv6 benchmarking' => ['2001:2::1'],
            'ORCHIDv2' => ['2001:20::1'],
            'IPv6 documentation' => ['2001:db8::1'],
            '6to4 private embedding' => ['2002:7f00:1::'],
            '6to4 public embedding' => ['2002:808:808::'],
            'new IPv6 documentation range' => ['3fff::1'],
            'segment routing SIDs' => ['5f00::1'],
            'IPv6 unique-local' => ['fd00::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv6 site-local' => ['fec0::1'],
            'IPv6 multicast' => ['ff02::1'],
            'ISATAP private embedding' => ['2001:4860::5efe:7f00:1'],
            'ISATAP public embedding' => ['2001:4860::200:5efe:808:808'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function publicAddresses(): array
    {
        return [
            'Cloudflare IPv4' => ['1.1.1.1'],
            'Google IPv4' => ['8.8.8.8'],
            'Quad9 IPv4' => ['9.9.9.10'],
            'Cloudflare IPv6' => ['2606:4700:4700::1111'],
            'Google IPv6' => ['2001:4860:4860::8888'],
            'Quad9 IPv6' => ['2620:fe::fe'],
        ];
    }
}
