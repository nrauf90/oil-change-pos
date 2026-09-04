<?php

namespace App\Console\Commands;

use App\Models\Central\ShopAccessSession;
use Illuminate\Console\Command;

/**
 * Close audited support sessions that outlived their grant.
 *
 * `SupportAccessManager::resume()` expires a grant on the operator's next
 * request, but an operator who simply closes the tab never makes one. Without
 * this sweep those rows read "Active" forever, so a second super-admin
 * auditing "who has access right now" cannot tell a live session from an
 * abandoned one — which is the signal that would trigger revocation.
 */
class SupportAccessSweepStaleCommand extends Command
{
    protected $signature = 'support:sweep-stale-access';

    protected $description = 'End audited support sessions that have outlived the configured grant ceiling';

    public function handle(): int
    {
        $maxLifetime = (int) config('auth.support_access.max_lifetime_minutes');

        if ($maxLifetime <= 0) {
            $this->components->info('No support access ceiling is configured; nothing to sweep.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes($maxLifetime);

        $stale = ShopAccessSession::query()
            ->whereNull('ended_at')
            ->where('started_at', '<=', $cutoff)
            ->get();

        foreach ($stale as $session) {
            $session->end();
        }

        $this->components->info(match ($stale->count()) {
            0 => 'No stale support sessions.',
            1 => 'Ended 1 stale support session.',
            default => "Ended {$stale->count()} stale support sessions.",
        });

        return self::SUCCESS;
    }
}
