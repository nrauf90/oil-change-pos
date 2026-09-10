<?php

namespace App\Support;

/**
 * The single place a sale total is ever computed.
 *
 * It is deliberately given nothing but the raw values typed on the checkout
 * screen. It has no access to Item::$reference_cost, no access to the database,
 * and no notion of a "list price" — so no background rate can leak into a bill.
 *
 * Everything is summed in integer paisa/cents, so 0.1 + 0.2 is 0.30 and not
 * 0.30000000000000004.
 *
 * ---------------------------------------------------------------------------
 * THE CONTRACT: what counts as "an amount a salesperson may type"
 * ---------------------------------------------------------------------------
 * This grammar is the *only* definition of a typed amount in the application,
 * and all three layers must implement exactly it:
 *
 *   1. this class (the stored and printed total),
 *   2. the toCents() in resources/views/pos/create.blade.php (the running
 *      total the customer is quoted on screen),
 *   3. StoreSaleRequest (the gate between the two).
 *
 * ACCEPTED — a plain decimal, optionally grouped by a human:
 *
 *   1200      1200.50      .5      5.      0.07      007      +12.50
 *   -100                                   (negative lines are discounts)
 *   1,200     1,234.56     1 234 567.89    1<NBSP>234.56
 *
 *   Grouping separators may be a comma, a space or a non-breaking space; they
 *   are permitted *only* in the integer part and *only* in groups of exactly
 *   three digits after a leading group of one to three. Anything else is not
 *   grouping.
 *
 * REFUSED — worth exactly 0.00, never an exception:
 *
 *   1e3  1E3  1.5e2      exponent notation. PHP's is_numeric() accepts it and
 *                        this class used to expand it to 1000.00 while the
 *                        browser quoted 0.00 — the screen and the invoice
 *                        disagreed about real money. A workshop counter never
 *                        legitimately types 1e3, so refusing it is right.
 *   0x10  0b101  0o10    non-decimal bases.
 *   1 234,56  1.234,56   the European convention, where the comma is the
 *                        decimal point. Reading it as 123456 or as 1234 would
 *                        be inventing money, so it is refused instead.
 *   1,23,456             lakh grouping. The screen formats money in groups of
 *                        three, so it is refused rather than guessed at.
 *   Rs 1200  1200/-      currency decoration.
 *   anything with more than 12 digits before the decimal point (see
 *   MAX_WHOLE_DIGITS), and anything that is not a string, int or finite float.
 *
 * Refusing is only safe because StoreSaleRequest applies the *same* grammar:
 * a refused amount bounces the form back to the counter with a message, rather
 * than being silently billed as zero.
 */
final class SaleTotalCalculator
{
    /**
     * The canonical grammar of a typed amount, once grouping separators have
     * been removed. Deliberately anchored, and deliberately without an
     * exponent part.
     *
     * Copied verbatim into the browser (create.blade.php) and reused by
     * StoreSaleRequest's `regex:` rule, so the three layers cannot drift.
     */
    public const PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    /**
     * A human-grouped integer part: 1,200 / 1 234 567.89. Group one holds the
     * sign, group two the grouped digits, group three the optional paisa.
     * Non-breaking spaces are folded to plain spaces before this is applied.
     */
    public const GROUPED_PATTERN = '/^([+-]?)(\d{1,3}(?:[, ]\d{3})+)(\.\d*)?$/';

    /**
     * How many digits may sit before the decimal point. 12 digits is a hundred
     * times more than the app's own validation allows and far past any real
     * bill, while keeping every intermediate value exact: 10^12 rupees is
     * 10^14 paisa, which fits both a PHP int and — after several hundred lines
     * are summed — JavaScript's 53-bit safe integer range. Past it, an amount
     * is absurd rather than large, and is worth zero.
     */
    public const MAX_WHOLE_DIGITS = 12;

    /**
     * Sum of the manually charged line prices, excluding labor and misc.
     *
     * @param  iterable<mixed>  $prices
     */
    public static function lineSubtotal(iterable $prices): string
    {
        return self::format(self::sumToCents($prices));
    }

    /**
     * Final payable amount: manual lines + manual labor + manual misc, less any discount.
     *
     * @param  iterable<mixed>  $prices
     */
    public static function total(iterable $prices, mixed $laborCharge, mixed $miscCharge, mixed $discount = null): string
    {
        return self::format(
            self::sumToCents($prices) + self::toCents($laborCharge) + self::toCents($miscCharge) - self::toCents($discount)
        );
    }

    /**
     * Normalise one hand-typed amount to a canonical "0.00" string.
     */
    public static function amount(mixed $value): string
    {
        return self::format(self::toCents($value));
    }

    /**
     * Rewrite a typed amount into the canonical PATTERN form, so validation can
     * accept "1,234.56" without the calculator's separator handling being dead
     * code on the checkout path.
     *
     * Only well-formed grouping is removed. Anything else — junk, exponents,
     * European decimal commas — is handed back untouched *on purpose*, so the
     * request's `regex:` rule refuses it and the counter is told, instead of
     * the row being quietly billed as zero.
     */
    public static function normalise(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $text = trim(str_replace("\u{00A0}", ' ', $value));

        if (preg_match(self::GROUPED_PATTERN, $text, $matches) !== 1) {
            return $text;
        }

        return $matches[1].str_replace([',', ' '], '', $matches[2]).($matches[3] ?? '');
    }

    /** @param iterable<mixed> $prices */
    private static function sumToCents(iterable $prices): int
    {
        $cents = 0;

        foreach ($prices as $price) {
            $cents += self::toCents($price);
        }

        return $cents;
    }

    /**
     * Parse a hand-typed amount into integer cents, rounding half away from zero.
     *
     * Floats are stringified first so we round what the salesperson actually
     * typed ("10.005") rather than its binary approximation (10.00499999…).
     * Beware: (string) $float is capped by PHP's `precision` ini (14 by
     * default), so a float carrying more digits than that has already been
     * rounded before it reaches us — which is exactly why the checkout hands
     * this class the raw strings from the form and never casts to float.
     *
     * Anything outside the contract in the class docblock is worth zero — a
     * typo must never invent money — and nothing here ever throws.
     */
    private static function toCents(mixed $value): int
    {
        if (is_int($value)) {
            return self::withinRange($value) ? $value * 100 : 0;
        }

        if (is_float($value)) {
            $value = is_finite($value) ? (string) $value : '';
        }

        if (! is_string($value)) {
            return 0;
        }

        $value = self::normalise($value);

        if (preg_match(self::PATTERN, $value) !== 1) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        // Leading zeroes are free: "007" is seven rupees, not a huge number.
        $whole = ltrim($whole, '0');

        if (strlen($whole) > self::MAX_WHOLE_DIGITS) {
            return 0;
        }

        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');

        $cents = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * Guard the int fast path: PHP_INT_MAX * 100 silently becomes a float, and
     * the ": int" return type would then throw a TypeError — a 500 on the
     * checkout screen instead of the graceful zero this class promises.
     */
    private static function withinRange(int $value): bool
    {
        $limit = 10 ** self::MAX_WHOLE_DIGITS;

        return $value < $limit && $value > -$limit;
    }

    private static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
