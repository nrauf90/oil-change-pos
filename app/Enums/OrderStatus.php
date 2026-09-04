<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Completed => 'Completed',
        };
    }

    /** A draft is the only state the counter may still change. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
