<?php

namespace App\Tenancy;

final class SystemDatabaseHostResolver implements DatabaseHostResolver
{
    /** @var array<string, list<string>> */
    private array $resolvedAddresses = [];

    public function resolve(#[\SensitiveParameter] string $host): array
    {
        if (array_key_exists($host, $this->resolvedAddresses)) {
            return $this->resolvedAddresses[$host];
        }

        $addresses = [];
        $pendingHosts = [$host];
        $visitedHosts = [];

        while ($pendingHosts !== [] && count($visitedHosts) < 16) {
            $pendingHost = array_shift($pendingHosts);
            $pendingHost = is_string($pendingHost) ? rtrim(strtolower($pendingHost), '.') : '';

            if ($pendingHost === '' || isset($visitedHosts[$pendingHost])) {
                continue;
            }

            $visitedHosts[$pendingHost] = true;
            $records = @dns_get_record($pendingHost, DNS_A | DNS_AAAA | DNS_CNAME);

            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip']) && is_string($record['ip'])) {
                        $addresses[] = $record['ip'];
                    }

                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }

                    if (isset($record['target']) && is_string($record['target'])) {
                        $pendingHosts[] = $record['target'];
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $addresses = $ipv4Addresses;
            }
        }

        return $this->resolvedAddresses[$host] = array_values(array_unique($addresses));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['resolved_addresses' => '[redacted]'];
    }
}
