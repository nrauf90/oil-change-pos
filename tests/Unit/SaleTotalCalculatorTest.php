<?php

namespace Tests\Unit;

use App\Support\SaleTotalCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SaleTotalCalculatorTest extends TestCase
{
    public function test_it_sums_only_the_manually_charged_line_prices(): void
    {
        $total = SaleTotalCalculator::total(
            prices: [1500, 800.50, 250.25],
            laborCharge: 0,
            miscCharge: 0,
        );

        $this->assertSame('2550.75', $total);
    }

    public function test_it_adds_labor_and_misc_charges_on_top_of_the_lines(): void
    {
        $total = SaleTotalCalculator::total(
            prices: [1000],
            laborCharge: 350,
            miscCharge: 125.50,
        );

        $this->assertSame('1475.50', $total);
    }

    public function test_a_sale_with_no_lines_is_just_labor_and_misc(): void
    {
        $this->assertSame('500.00', SaleTotalCalculator::total([], 400, 100));
    }

    public function test_an_entirely_empty_sale_totals_zero(): void
    {
        $this->assertSame('0.00', SaleTotalCalculator::total([], null, null));
    }

    public function test_blank_and_null_prices_count_as_zero(): void
    {
        $total = SaleTotalCalculator::total(['', null, '  ', 300], '', null);

        $this->assertSame('300.00', $total);
    }

    public function test_it_does_not_suffer_binary_floating_point_drift(): void
    {
        // 0.1 + 0.2 in IEEE-754 floats is 0.30000000000000004.
        $this->assertSame('0.30', SaleTotalCalculator::total([0.1, 0.2], 0, 0));
    }

    public function test_it_stays_exact_across_many_awkward_decimal_lines(): void
    {
        $prices = array_fill(0, 100, 0.07); // 100 x 7 paisa = 7.00 exactly

        $this->assertSame('7.00', SaleTotalCalculator::total($prices, 0, 0));
    }

    public function test_numeric_strings_from_form_input_are_accepted(): void
    {
        $total = SaleTotalCalculator::total(['1200.00', '99.99'], '50', '0.01');

        $this->assertSame('1350.00', $total);
    }

    public function test_it_ignores_non_numeric_junk_rather_than_crashing(): void
    {
        $this->assertSame('100.00', SaleTotalCalculator::total(['abc', 100], 'n/a', null));
    }

    public function test_it_rounds_half_up_to_two_decimal_places(): void
    {
        $this->assertSame('10.01', SaleTotalCalculator::total([10.005], 0, 0));
    }

    public function test_negative_lines_are_supported_for_discounts(): void
    {
        $this->assertSame('900.00', SaleTotalCalculator::total([1000, -100], 0, 0));
    }

    public function test_it_can_report_the_line_subtotal_separately_from_the_total(): void
    {
        $this->assertSame('1800.00', SaleTotalCalculator::lineSubtotal([1000, 800]));
    }

    /* ------------------------------------------------------------------
     * The typed-amount contract. See the class docblock on
     * SaleTotalCalculator: plain decimals only, human thousands separators
     * allowed, everything else is worth zero and nothing ever throws.
     * ------------------------------------------------------------------ */

    /**
     * M1: PHP's is_numeric() accepts "1e3" and the calculator used to expand it
     * to 1000.00, while the browser's regex rejected it and quoted 0.00 — the
     * screen and the invoice disagreed about real money. A workshop counter
     * never legitimately types 1e3, so both sides now agree it is junk.
     */
    #[DataProvider('exponentNotationProvider')]
    public function test_exponent_notation_is_not_an_amount(string $typed): void
    {
        $this->assertSame('0.00', SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{string}> */
    public static function exponentNotationProvider(): array
    {
        return [
            'lower case' => ['1e3'],
            'upper case' => ['1E3'],
            'negative exponent' => ['1e-3'],
            'explicit plus' => ['1e+3'],
            'with a mantissa' => ['1.5e2'],
            'exponent only' => ['e3'],
        ];
    }

    /**
     * M2: the calculator has always stripped thousands separators, but the
     * validation layer rejected them before it ever ran, so the stripping was
     * unreachable on the checkout path. This is the grammar the request now
     * normalises away before validating.
     */
    #[DataProvider('groupedNumberProvider')]
    public function test_human_thousands_separators_are_accepted(string $typed, string $expected): void
    {
        $this->assertSame($expected, SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{string, string}> */
    public static function groupedNumberProvider(): array
    {
        return [
            'comma grouped' => ['1,200', '1200.00'],
            'comma grouped with paisa' => ['1,234.56', '1234.56'],
            'space grouped' => ['1 234.56', '1234.56'],
            'non breaking space grouped' => ["1\u{00A0}234.56", '1234.56'],
            'several groups' => ['1,234,567.89', '1234567.89'],
            'negative grouped' => ['-1,234.56', '-1234.56'],
            'padded and grouped' => ['  1,234.56  ', '1234.56'],
        ];
    }

    /**
     * A separator is a thousands separator and nothing else. "1 234,56" and
     * "1.234,56" are the European convention where the comma is the decimal
     * point; reading them as 123456.00 or 1234.00 would be inventing money, so
     * they are refused outright — and the request's regex refuses them too, so
     * the counter is told about it instead of being quoted a wrong number.
     */
    #[DataProvider('misgroupedNumberProvider')]
    public function test_separators_that_are_not_thousands_groups_are_worth_zero(string $typed): void
    {
        $this->assertSame('0.00', SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{string}> */
    public static function misgroupedNumberProvider(): array
    {
        return [
            'european decimal comma' => ['1 234,56'],
            'european full stop grouping' => ['1.234,56'],
            'south asian lakh grouping' => ['1,23,456'],
            'two digit group' => ['12,34'],
            'four digit group' => ['1,2345'],
            'separator inside the paisa' => ['12.3 4'],
            'trailing separator' => ['1,'],
            'leading separator' => [',200'],
            'bare separator' => [','],
        ];
    }

    #[DataProvider('awkwardButValidProvider')]
    public function test_awkward_but_still_plain_decimals(mixed $typed, string $expected): void
    {
        $this->assertSame($expected, SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{mixed, string}> */
    public static function awkwardButValidProvider(): array
    {
        return [
            'leading point' => ['.5', '0.50'],
            'trailing point' => ['5.', '5.00'],
            'rounds down below half a paisa' => ['-0.004', '0.00'],
            'leading zeroes' => ['007', '7.00'],
            'surrounding whitespace' => ['  12.50  ', '12.50'],
            'explicit plus sign' => ['+12.50', '12.50'],
            'largest accepted amount' => ['999999999999.99', '999999999999.99'],
        ];
    }

    #[DataProvider('notANumberProvider')]
    public function test_things_that_are_not_plain_decimals_are_worth_zero(mixed $typed): void
    {
        $this->assertSame('0.00', SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{mixed}> */
    public static function notANumberProvider(): array
    {
        return [
            'hexadecimal' => ['0x10'],
            'octal looking' => ['0o10'],
            'binary' => ['0b101'],
            'currency symbol' => ['Rs 1200'],
            'trailing unit' => ['1200/-'],
            'two decimal points' => ['1.2.3'],
            'sign only' => ['-'],
            'infinity word' => ['INF'],
            'nan word' => ['NAN'],
            'a boolean' => [true],
            'an array' => [[100]],
            'a non finite float' => [INF],
            'a nan float' => [NAN],
        ];
    }

    /**
     * N2: ((int) $whole) * 100 used to overflow int -> float, and the ": int"
     * return type then threw a TypeError — a 500 on the checkout screen, not
     * the graceful zero the method's own docblock promises. Nothing below may
     * throw; every case is an explicit 0.00.
     */
    #[DataProvider('absurdlyLargeProvider')]
    public function test_absurdly_large_amounts_return_zero_instead_of_throwing(mixed $typed): void
    {
        $this->assertSame('0.00', SaleTotalCalculator::amount($typed));
    }

    /** @return array<string, array{mixed}> */
    public static function absurdlyLargeProvider(): array
    {
        return [
            'PHP_INT_MAX as an int' => [PHP_INT_MAX],
            'PHP_INT_MIN as an int' => [PHP_INT_MIN],
            'PHP_INT_MAX as a string' => [(string) PHP_INT_MAX],
            'an exponent that would be huge' => ['1e30'],
            'a float that would be huge' => [1e30],
            'a thirty digit integer string' => ['123456789012345678901234567890'],
            'thirteen digits, one past the cap' => ['1000000000000'],
            'thirteen digits with paisa' => ['1234567890123.45'],
        ];
    }

    public function test_a_long_run_of_leading_zeroes_is_not_mistaken_for_a_huge_number(): void
    {
        $this->assertSame('12.50', SaleTotalCalculator::amount('0000000000000000012.50'));
    }

    public function test_a_bill_mixing_junk_and_real_lines_never_throws(): void
    {
        $total = SaleTotalCalculator::total(
            prices: ['1,200.50', '1e3', PHP_INT_MAX, '0x10', '99.50'],
            laborCharge: '1 234,56',
            miscCharge: '  350  ',
        );

        $this->assertSame('1650.00', $total);
    }

    /**
     * PHP's `precision` ini (14 by default) governs (string) $float, so a float
     * carrying more than 14 significant digits is rounded by PHP *before* the
     * calculator sees its digits. This is exactly why the checkout hands the
     * calculator the raw strings from the form and never casts to float.
     */
    public function test_floats_beyond_phps_string_precision_are_rounded_by_php_not_by_us(): void
    {
        $this->assertSame('14', ini_get('precision'), 'This test pins the default precision ini.');

        // (string) 1234567890.12345678 === '1234567890.1235' at precision=14,
        // so the 5th decimal is already gone before we parse.
        $this->assertSame('1234567890.12', SaleTotalCalculator::amount(1234567890.12345678));

        // The same money typed as a string keeps every digit the salesperson typed.
        $this->assertSame('1234567890.12', SaleTotalCalculator::amount('1234567890.12345678'));

        // 17 significant digits as a float: PHP rounds to 14 and the 15th digit
        // onwards simply does not exist by the time we look at it.
        $this->assertSame('0.10', SaleTotalCalculator::amount(0.099999999999999999));
    }

    public function test_the_canonical_patterns_are_published_for_the_other_layers_to_reuse(): void
    {
        $this->assertSame('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', SaleTotalCalculator::PATTERN);
        $this->assertSame('/^([+-]?)(\d{1,3}(?:[, ]\d{3})+)(\.\d*)?$/', SaleTotalCalculator::GROUPED_PATTERN);
    }

    #[DataProvider('normaliseProvider')]
    public function test_normalise_prepares_a_typed_amount_for_validation(mixed $typed, mixed $expected): void
    {
        $this->assertSame($expected, SaleTotalCalculator::normalise($typed));
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function normaliseProvider(): array
    {
        return [
            'strips valid grouping' => ['1,234.56', '1234.56'],
            'strips spaces used as grouping' => ['1 234 567', '1234567'],
            'strips a non breaking space' => ["1\u{00A0}234", '1234'],
            'trims' => ['  12.50 ', '12.50'],
            'leaves a plain decimal alone' => ['12.50', '12.50'],
            'leaves junk alone so validation can complain' => ['1 234,56', '1 234,56'],
            'leaves exponents alone so validation can complain' => ['1e3', '1e3'],
            'leaves the empty string alone' => ['', ''],
            'leaves null alone' => [null, null],
            'leaves non strings alone' => [1200, 1200],
        ];
    }
}
