<?php

namespace App\Enums;

enum SaleLineType: string
{
    case Product = 'product';
    case Repair = 'repair';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Repair => 'Repair',
            self::Custom => 'Custom',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Product => 'bg-sky-100 text-sky-800',
            self::Repair => 'bg-violet-100 text-violet-800',
            self::Custom => 'bg-amber-100 text-amber-900',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
