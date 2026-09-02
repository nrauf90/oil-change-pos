<?php

namespace App\Support;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Vehicle service-history lookup for the workshop floor.
 *
 * Staff type a phone number three different ways ("0300-123 4567",
 * "03001234567", "+92 300 1234567") and a plate two ("ABC-123", "abc 123"),
 * so both are normalised on the fly in SQL rather than trusting whatever was
 * keyed in at the counter months ago.
 *
 * The `$withPricing` flag is the PRD's technician restriction made real: when
 * it is false the money columns are never even SELECTed, so no amount can leak
 * through a stray Blade tag, a debug dump or a serialised model.
 */
final class ServiceHistory
{
    /** Plenty for one vehicle's life at a single shop, and a hard cap on a broad term. */
    public const MAX_VISITS = 50;

    /**
     * How many trailing digits of a phone number identify a customer.
     *
     * A Pakistani mobile is 10 significant digits; the leading 0 or +92 is
     * noise that differs between the three staff who typed it.
     */
    private const PHONE_SIGNIFICANT_DIGITS = 10;

    /** Characters staff sprinkle through a phone number. */
    private const PHONE_NOISE = [' ', '-', '(', ')', '+', '.', '/'];

    /** Characters staff sprinkle through a plate. */
    private const PLATE_NOISE = [' ', '-', '.', '/'];

    /** @var array<int, string> */
    private const SALE_COLUMNS = [
        'id', 'invoice_number', 'customer_name', 'phone', 'vehicle_model',
        'vehicle_plate', 'mileage', 'next_checkup_mileage', 'notes', 'created_at',
    ];

    /** @var array<int, string> */
    private const SALE_MONEY_COLUMNS = ['labor_charge', 'misc_charge', 'total_amount'];

    /** @var array<int, string> Includes sale_id so the hasMany can still match rows up. */
    private const LINE_COLUMNS = ['id', 'sale_id', 'item_name', 'type'];

    /** @var array<int, string> */
    private const LINE_MONEY_COLUMNS = ['manually_charged_price'];

    /**
     * Past visits matching a phone number, a plate or a customer name.
     *
     * @return EloquentCollection<int, Sale>
     */
    public static function lookup(?string $term, bool $withPricing = true): EloquentCollection
    {
        $term = trim((string) $term);

        if ($term === '') {
            /** @var EloquentCollection<int, Sale> */
            return new EloquentCollection;
        }

        $phone = self::phoneNeedle($term);
        $plate = self::normalisePlate($term);

        return Sale::query()
            ->select(self::saleColumns($withPricing))
            ->with(['lines' => fn ($lines) => $lines->select(self::lineColumns($withPricing))->orderBy('id')])
            ->where(function (Builder $where) use ($term, $phone, $plate): void {
                // Always present, so the group is never empty and a term that
                // normalises away to nothing (say "%") matches nothing at all.
                $where->whereRaw(
                    'lower(customer_name) like ? escape ?',
                    ['%'.mb_strtolower(self::escapeLike($term)).'%', '\\'],
                );

                if ($phone !== '') {
                    $where->orWhereRaw(
                        self::stripped('phone', self::PHONE_NOISE).' like ? escape ?',
                        ['%'.$phone.'%', '\\'],
                    );
                }

                if ($plate !== '') {
                    $where->orWhereRaw(
                        self::plateExpression('vehicle_plate').' like ? escape ?',
                        ['%'.$plate.'%', '\\'],
                    );
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_VISITS)
            ->get();
    }

    /** Every digit of a phone number, with the separators staff type stripped out. */
    public static function normalisePhone(?string $phone): string
    {
        return (string) preg_replace('/\D+/', '', (string) $phone);
    }

    /**
     * The trailing significant digits that identify a customer regardless of
     * how the leading 0 / +92 was written.
     */
    public static function phoneNeedle(?string $phone): string
    {
        $digits = self::normalisePhone($phone);

        return strlen($digits) > self::PHONE_SIGNIFICANT_DIGITS
            ? substr($digits, -self::PHONE_SIGNIFICANT_DIGITS)
            : $digits;
    }

    /** A plate reduced to bare upper-case alphanumerics: "leb 4477" -> "LEB4477". */
    public static function normalisePlate(?string $plate): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $plate));
    }

    /**
     * Neutralise the LIKE wildcards, so searching for "%" looks for a literal
     * percent sign instead of dumping the whole customer table.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * SQL that reduces a plate column to the same bare upper-case form
     * {@see normalisePlate()} produces in PHP. Shared with Inspection so both
     * screens agree on what "the same car" means.
     */
    public static function plateExpression(string $column): string
    {
        return 'upper('.self::stripped($column, self::PLATE_NOISE).')';
    }

    /**
     * A nested REPLACE() chain that strips the given noise characters from a
     * column. Portable across SQLite and MySQL — no regex functions needed.
     *
     * @param  array<int, string>  $noise
     */
    private static function stripped(string $column, array $noise): string
    {
        $expression = $column;

        foreach ($noise as $character) {
            // Every character is a hard-coded constant above, never user input.
            $expression = "replace({$expression}, '{$character}', '')";
        }

        return $expression;
    }

    /** @return array<int, string> */
    private static function saleColumns(bool $withPricing): array
    {
        return $withPricing
            ? [...self::SALE_COLUMNS, ...self::SALE_MONEY_COLUMNS]
            : self::SALE_COLUMNS;
    }

    /** @return array<int, string> */
    private static function lineColumns(bool $withPricing): array
    {
        return $withPricing
            ? [...self::LINE_COLUMNS, ...self::LINE_MONEY_COLUMNS]
            : self::LINE_COLUMNS;
    }
}
