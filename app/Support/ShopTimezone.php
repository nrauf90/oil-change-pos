<?php

namespace App\Support;

use App\Tenancy\TenantContext;

/**
 * The timezone a shop's own day is measured in.
 *
 * Money that reconciles a "day" — the cash drawer, today's dashboard figures —
 * has to close on the shop's midnight, not the storage timezone's. A shop in
 * America/Chicago reconciling at 20:00 local is already past UTC midnight, so a
 * UTC day boundary drops that morning's takings from the drawer entirely.
 *
 * Falls back to the storage timezone outside a tenant context (console
 * commands, the central host, unit tests) so callers never branch on it.
 */
final class ShopTimezone
{
    public static function current(): string
    {
        $context = app(TenantContext::class);

        if (! $context->initialized()) {
            return self::storage();
        }

        $timezone = (string) $context->shop()->timezone;

        return $timezone !== '' ? $timezone : self::storage();
    }

    public static function storage(): string
    {
        return (string) config('app.timezone');
    }
}
