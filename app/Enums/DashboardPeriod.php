<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

enum DashboardPeriod: string
{
    case Today = 'today';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Week => 'This week',
            self::Month => 'This month',
        };
    }

    public function previousLabel(): string
    {
        return match ($this) {
            self::Today => 'yesterday',
            self::Week => 'last week',
            self::Month => 'last month',
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function currentRange(?string $timezone = null): array
    {
        $now = now($timezone);

        return match ($this) {
            self::Today => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            self::Week => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            self::Month => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function previousRange(?string $timezone = null): array
    {
        $now = now($timezone);

        return match ($this) {
            self::Today => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            self::Week => [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()],
            self::Month => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
        };
    }

    /** @return array<int, string> */
    public function chartLabels(): array
    {
        return match ($this) {
            self::Today => collect(range(0, 23))->map(fn (int $hour): string => Carbon::createFromTime($hour)->format('ga'))->all(),
            self::Week => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            self::Month => array_map('strval', range(1, now()->daysInMonth)),
        };
    }

    public function bucket(Carbon $date): int
    {
        return match ($this) {
            self::Today => $date->hour,
            self::Week => $date->dayOfWeekIso - 1,
            self::Month => $date->day - 1,
        };
    }
}
