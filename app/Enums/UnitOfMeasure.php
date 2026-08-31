<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an item's stock is counted.
 *
 * A filter is counted in pieces. Oil is bought by the carton and poured by the
 * litre; AC gas is bought by the cylinder and charged by the kilogram. Anything
 * measured is held as a decimal, because half a litre is a real amount of oil.
 */
enum UnitOfMeasure: string
{
    case Piece = 'piece';
    case Litre = 'litre';
    case Kilogram = 'kilogram';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'Pieces',
            self::Litre => 'Litres',
            self::Kilogram => 'Kilograms',
        };
    }

    /** Short form for tight table cells and stock badges. */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Piece => '',
            self::Litre => 'L',
            self::Kilogram => 'kg',
        };
    }

    /** What one unit inside a pack is called, e.g. a bottle in a carton. */
    public function unitNoun(): string
    {
        return match ($this) {
            self::Piece => 'piece',
            self::Litre => 'bottle',
            self::Kilogram => 'cylinder',
        };
    }

    /**
     * Measured items are drawn down by a typed amount rather than by whole
     * units, so a sale line records how much was actually dispensed.
     */
    public function isMeasured(): bool
    {
        return $this !== self::Piece;
    }

    /** Decimal places to show. Nobody stocks a third of a filter. */
    public function precision(): int
    {
        return $this->isMeasured() ? 3 : 0;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
