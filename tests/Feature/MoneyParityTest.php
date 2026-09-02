<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SaleTotalCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The on-screen total and the saved invoice must never disagree.
 *
 * The sum of the hand-typed prices exists twice: once in PHP
 * (App\Support\SaleTotalCalculator, which produces the stored and printed
 * total) and once in JavaScript (the toCents/money pair inside
 * resources/views/pos/create.blade.php, which produces the running total the
 * customer is quoted). If those two ever read a typed string differently, the
 * counter quotes one number and the invoice charges another.
 *
 * This test holds them together three ways:
 *
 *  1. it runs the *real, rendered* JavaScript under node against a shared list
 *     of typed strings and demands identical paisa (the strong check);
 *  2. it asserts the shipped JS literally contains the server's canonical
 *     patterns, so the two cannot drift silently even without node;
 *  3. it walks a comma-grouped price and an exponent through the actual
 *     checkout route, which is where the validation layer has to agree too.
 */
class MoneyParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every string a salesperson might plausibly get into a price field,
     * mapped to the one answer both layers must give. Browser inputs are
     * always strings, so this list is deliberately all strings.
     *
     * @return array<string, string>
     */
    public static function sharedInputs(): array
    {
        return [
            // ordinary money
            '' => '0.00',
            '  ' => '0.00',
            '1200' => '1200.00',
            '1200.50' => '1200.50',
            '0.07' => '0.07',
            '-100' => '-100.00',

            // awkward but legitimate typing
            '.5' => '0.50',
            '5.' => '5.00',
            '007' => '7.00',
            '  12.50  ' => '12.50',
            '+12.50' => '12.50',
            '-0.004' => '0.00',
            '0.005' => '0.01',
            '10.005' => '10.01',
            '0000000000000000012.50' => '12.50',

            // M2: human thousands separators
            '1,200' => '1200.00',
            '1,234.56' => '1234.56',
            '1 234.56' => '1234.56',
            "1\u{00A0}234.56" => '1234.56',
            '1,234,567.89' => '1234567.89',
            '-1,234.56' => '-1234.56',

            // M1: exponent notation is a typo, not a thousand
            '1e3' => '0.00',
            '1E3' => '0.00',
            '1e-3' => '0.00',
            '1.5e2' => '0.00',
            '1e30' => '0.00',

            // separators that are not thousands groups
            '1 234,56' => '0.00',
            '1.234,56' => '0.00',
            '1,23,456' => '0.00',
            '12,34' => '0.00',

            // plain junk
            '0x10' => '0.00',
            'abc' => '0.00',
            'Rs 1200' => '0.00',
            '1200/-' => '0.00',
            '1.2.3' => '0.00',
            '-' => '0.00',

            // N2: absurdly large is zero on both sides, and throws on neither
            '999999999999.99' => '999999999999.99',
            '1000000000000' => '0.00',
            '9223372036854775807' => '0.00',
            '123456789012345678901234567890' => '0.00',
        ];
    }

    public function test_the_server_reads_every_shared_input_the_way_the_contract_says(): void
    {
        foreach (self::sharedInputs() as $typed => $expected) {
            $this->assertSame(
                $expected,
                SaleTotalCalculator::amount((string) $typed),
                sprintf('PHP misread %s', var_export((string) $typed, true)),
            );
        }
    }

    /**
     * The strong parity check: run the JavaScript that is actually served to
     * the counter, over the same list, and compare paisa for paisa.
     */
    public function test_the_browser_and_the_server_agree_paisa_for_paisa(): void
    {
        $this->skipWithoutNode();

        $inputs = array_map('strval', array_keys(self::sharedInputs()));
        $actual = $this->runBrowserToCents($inputs);

        foreach ($inputs as $index => $typed) {
            $expected = SaleTotalCalculator::amount($typed);

            $this->assertSame(
                $expected,
                $actual[$index]['amount'],
                sprintf(
                    'The screen would quote %s for %s while the invoice would say %s.',
                    $actual[$index]['amount'],
                    var_export($typed, true),
                    $expected,
                ),
            );
        }
    }

    /**
     * The screen is allowed exactly one cosmetic difference from the server:
     * it groups thousands for readability. The digits either side of the
     * decimal point must still be identical.
     */
    public function test_the_only_difference_on_screen_is_thousands_grouping(): void
    {
        $this->skipWithoutNode();

        $inputs = array_map('strval', array_keys(self::sharedInputs()));
        $actual = $this->runBrowserToCents($inputs);

        foreach ($inputs as $index => $typed) {
            $this->assertSame(
                SaleTotalCalculator::amount($typed),
                str_replace(',', '', $actual[$index]['money']),
                sprintf('money() and format() disagree about %s', var_export($typed, true)),
            );
        }
    }

    /**
     * Even with no node on the machine, the two layers cannot drift: the
     * shipped script has to carry the server's own patterns, character for
     * character.
     */
    public function test_the_browser_uses_the_servers_canonical_patterns(): void
    {
        $script = $this->renderedCheckoutScript();

        $this->assertStringContainsString(
            SaleTotalCalculator::PATTERN,
            $script,
            'The checkout script no longer uses SaleTotalCalculator::PATTERN verbatim.',
        );

        $this->assertStringContainsString(
            SaleTotalCalculator::GROUPED_PATTERN,
            $script,
            'The checkout script no longer uses SaleTotalCalculator::GROUPED_PATTERN verbatim.',
        );

        $this->assertStringContainsString(
            'whole.length > '.SaleTotalCalculator::MAX_WHOLE_DIGITS,
            $script,
            'The browser and the server disagree about how many digits are absurd.',
        );

        // The old blanket strip is what made "1.234,56" read as 1.23 on screen.
        $this->assertStringNotContainsString(
            'replace(/[,\s]/g',
            $script,
            'The checkout script is stripping separators blindly again.',
        );
    }

    /* ------------------------------------------------------------------
     * End to end: the validation layer has to agree with both of them.
     *
     * BLOCKED ON HANDOFF — both tests below fail until StoreSaleRequest
     * (owned by another agent) normalises separators in prepareForValidation()
     * and adds the `regex:` rule. See the task report.
     * ------------------------------------------------------------------ */

    public function test_a_comma_grouped_price_is_accepted_by_the_checkout(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.store'), $this->payload('1,200.50'));

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['total_amount' => '1200.50'], 'tenant');
    }

    public function test_an_exponent_price_is_refused_rather_than_billed_as_zero(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->post(route('sales.store'), $this->payload('1e3'));

        $response->assertSessionHasErrors('lines.0.manually_charged_price');
        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    /* ---------------------------- helpers ---------------------------- */

    /** @return array<string, mixed> */
    private function payload(string $price): array
    {
        return [
            'customer_name' => 'Ali Raza',
            'vehicle_plate' => 'ABC-123',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [
                [
                    'item_id' => null,
                    'item_name' => 'Custom work',
                    'type' => 'custom',
                    'quantity' => 1,
                    'manually_charged_price' => $price,
                ],
            ],
        ];
    }

    /**
     * Pull normalise/toCents/money straight out of the page the counter is
     * served, so this test can never drift from what actually ships.
     */
    private function renderedCheckoutScript(): string
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('pos.create'))
            ->assertOk()
            ->getContent();

        // Comma-joined so the three of them drop straight into an object literal.
        return implode(",\n", array_map(
            fn (string $signature): string => $this->extractMethod($html, $signature),
            ['normalise(value) {', 'toCents(value) {', 'money(cents) {'],
        ));
    }

    /**
     * Lift one object-literal method, brace-balanced, out of the rendered page.
     */
    private function extractMethod(string $html, string $signature): string
    {
        $start = strpos($html, $signature);

        $this->assertNotFalse($start, sprintf('The checkout page no longer defines %s', $signature));

        $depth = 0;

        for ($i = $start + strlen($signature) - 1, $length = strlen($html); $i < $length; $i++) {
            $depth += match ($html[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return substr($html, $start, $i - $start + 1);
            }
        }

        $this->fail(sprintf('%s is not brace-balanced in the rendered page.', $signature));
    }

    /**
     * @param  list<string>  $inputs
     * @return list<array{amount: string, money: string}>
     */
    private function runBrowserToCents(array $inputs): array
    {
        $script = <<<JS
        const cart = {
        {$this->renderedCheckoutScript()}
        };

        const inputs = {$this->jsonInputs($inputs)};

        console.log(JSON.stringify(inputs.map(function (typed) {
            const cents = cart.toCents(typed);
            const sign = cents < 0 ? '-' : '';
            const abs = Math.abs(cents);

            return {
                amount: sign + Math.floor(abs / 100) + '.' + String(abs % 100).padStart(2, '0'),
                money: cart.money(cents),
            };
        })));
        JS;

        $file = tempnam(sys_get_temp_dir(), 'parity').'.js';
        file_put_contents($file, $script);

        try {
            $output = [];
            $status = 0;
            exec('node '.escapeshellarg($file).' 2>&1', $output, $status);

            $this->assertSame(0, $status, "node could not run the checkout script:\n".implode("\n", $output));

            /** @var list<array{amount: string, money: string}>|null $decoded */
            $decoded = json_decode(implode('', $output), true);

            $this->assertIsArray($decoded, 'node did not return JSON: '.implode("\n", $output));
            $this->assertCount(count($inputs), $decoded);

            return $decoded;
        } finally {
            @unlink($file);
        }
    }

    /** @param list<string> $inputs */
    private function jsonInputs(array $inputs): string
    {
        return (string) json_encode($inputs, JSON_THROW_ON_ERROR);
    }

    private function skipWithoutNode(): void
    {
        $output = [];
        $status = 0;
        exec('node -v 2>&1', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('node is not available, so the real browser code cannot be executed here.');
        }
    }
}
