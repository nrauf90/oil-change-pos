<?php

namespace App\Enums;

enum ItemType: string
{
    case Product = 'product';
    case Repair = 'repair';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Repair => 'Repair / Service',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
