<?php

namespace App\Enums;

/**
 * The verdict on one check-point of a multi-point inspection.
 *
 * Three states only: it is fine, it wants watching, or the car should not
 * leave the ramp. A technician taps one of three big buttons — no free-text
 * severity, no numbers, and deliberately no money.
 */
enum InspectionStatus: string
{
    case Ok = 'ok';
    case NeedsAttention = 'attention';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::NeedsAttention => 'Needs attention',
            self::Urgent => 'Urgent',
        };
    }

    /** Pill colours for the list and the report. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Ok => 'bg-emerald-100 text-emerald-800',
            self::NeedsAttention => 'bg-amber-100 text-amber-900',
            self::Urgent => 'bg-red-100 text-red-800',
        };
    }

    /** Colours for the selected state of the big tap target on the form. */
    public function tapTargetClasses(): string
    {
        return match ($this) {
            self::Ok => 'peer-checked:border-emerald-600 peer-checked:bg-emerald-600 peer-checked:text-white',
            self::NeedsAttention => 'peer-checked:border-amber-500 peer-checked:bg-amber-500 peer-checked:text-slate-900',
            self::Urgent => 'peer-checked:border-red-600 peer-checked:bg-red-600 peer-checked:text-white',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
