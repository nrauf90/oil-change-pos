<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CounterScripts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counter scripts are static, hand-written content — the only reason this
 * suite touches the database is to sign in a member of staff, since every route
 * now sits behind auth. What matters is that every entry is complete, that the three
 * PRD-mandated groups are all present, and — critically — that the embeddable
 * widget can never break the checkout form it is rendered next to.
 */
class CounterScriptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_scripts_page_loads(): void
    {
        $this->get(route('scripts.index'))->assertOk();
    }

    public function test_the_scripts_page_shows_all_three_group_titles(): void
    {
        $response = $this->get(route('scripts.index'))->assertOk();

        foreach (CounterScripts::all() as $group) {
            $response->assertSeeText($group['title']);
        }

        $body = (string) $response->getContent();

        $this->assertStringContainsStringIgnoringCase('check-in', $body);
        $this->assertStringContainsStringIgnoringCase('upsell', $body);
        $this->assertStringContainsStringIgnoringCase('objection', $body);
    }

    public function test_the_scripts_page_renders_every_script_line(): void
    {
        $response = $this->get(route('scripts.index'))->assertOk();

        foreach (CounterScripts::all() as $group) {
            foreach ($group['entries'] as $entry) {
                $response->assertSeeText($entry['title']);

                foreach ($entry['lines'] as $line) {
                    $response->assertSeeText($line);
                }
            }
        }
    }

    public function test_the_check_in_group_mentions_mileage_plate_and_phone(): void
    {
        $text = $this->groupText(CounterScripts::CHECK_IN);

        foreach (['mileage', 'plate', 'name', 'model'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $text);
        }

        $this->assertTrue(
            str_contains($text, 'phone') || str_contains($text, 'whatsapp') || str_contains($text, 'mobile'),
            'The check-in group must ask for a phone / mobile / WhatsApp number.'
        );
    }

    public function test_the_upsell_group_covers_all_three_oil_grades(): void
    {
        $text = $this->groupText(CounterScripts::UPSELL);

        foreach (['conventional', 'semi-synthetic', 'full synthetic'] as $grade) {
            $this->assertStringContainsStringIgnoringCase($grade, $text);
        }
    }

    public function test_the_objection_group_contains_the_too_expensive_handler(): void
    {
        $this->assertStringContainsStringIgnoringCase(
            'too expensive',
            $this->groupText(CounterScripts::OBJECTIONS)
        );
    }

    public function test_the_objection_group_contains_the_phone_number_handler(): void
    {
        $this->assertStringContainsStringIgnoringCase(
            'why do you need my phone number',
            $this->groupText(CounterScripts::OBJECTIONS)
        );
    }

    public function test_all_returns_exactly_three_groups(): void
    {
        $groups = CounterScripts::all();

        $this->assertCount(3, $groups);
        $this->assertSame(
            [CounterScripts::CHECK_IN, CounterScripts::UPSELL, CounterScripts::OBJECTIONS],
            array_column($groups, 'key')
        );
    }

    public function test_every_group_has_a_key_title_blurb_and_entries(): void
    {
        foreach (CounterScripts::all() as $index => $group) {
            $where = "group #{$index}";

            foreach (['key', 'title', 'blurb'] as $field) {
                $this->assertArrayHasKey($field, $group, "{$where} is missing '{$field}'.");
                $this->assertIsString($group[$field], "{$where} '{$field}' must be a string.");
                $this->assertNotSame('', trim($group[$field]), "{$where} has an empty '{$field}'.");
            }

            $this->assertArrayHasKey('entries', $group, "{$where} is missing 'entries'.");
            $this->assertIsArray($group['entries']);
            $this->assertNotEmpty($group['entries'], "{$where} ({$group['key']}) has no entries.");
        }
    }

    public function test_every_entry_has_a_title_and_at_least_one_script_line(): void
    {
        foreach (CounterScripts::all() as $group) {
            foreach ($group['entries'] as $index => $entry) {
                $where = "{$group['key']} entry #{$index}";

                $this->assertArrayHasKey('title', $entry, "{$where} is missing a title.");
                $this->assertNotSame('', trim($entry['title']), "{$where} has an empty title.");

                $this->assertArrayHasKey('lines', $entry, "{$where} is missing 'lines'.");
                $this->assertIsArray($entry['lines']);
                $this->assertNotEmpty($entry['lines'], "{$where} has no script lines.");

                foreach ($entry['lines'] as $lineIndex => $line) {
                    $this->assertIsString($line, "{$where} line #{$lineIndex} must be a string.");
                    $this->assertNotSame('', trim($line), "{$where} line #{$lineIndex} is empty.");
                }

                $this->assertArrayHasKey('note', $entry, "{$where} must declare a 'note' key (null is allowed).");
            }
        }
    }

    public function test_groups_returns_the_key_and_title_of_each_group(): void
    {
        $groups = CounterScripts::groups();

        $this->assertCount(3, $groups);

        foreach ($groups as $group) {
            $this->assertSame(['key', 'title'], array_keys($group));
        }

        $this->assertSame(
            array_column(CounterScripts::all(), 'title'),
            array_column($groups, 'title')
        );
    }

    public function test_group_returns_the_matching_group_for_a_known_key(): void
    {
        $group = CounterScripts::group(CounterScripts::UPSELL);

        $this->assertIsArray($group);
        $this->assertSame(CounterScripts::UPSELL, $group['key']);
        $this->assertNotEmpty($group['entries']);
    }

    public function test_group_returns_null_for_an_unknown_key_without_a_fatal_error(): void
    {
        $this->assertNull(CounterScripts::group('does-not-exist'));
        $this->assertNull(CounterScripts::group(''));
    }

    public function test_the_embeddable_component_renders_on_its_own(): void
    {
        $html = $this->renderWidget();

        $this->assertStringContainsString('x-data', $html);
        $this->assertStringContainsString('no-print', $html);
        $this->assertStringContainsStringIgnoringCase('objection', $html);
    }

    public function test_the_embeddable_component_contains_no_form_and_no_submit_button(): void
    {
        $html = strtolower($this->renderWidget());

        $this->assertStringNotContainsString('<form', $html, 'The widget must never nest a form inside the checkout form.');
        $this->assertStringNotContainsString('type="submit"', $html, 'The widget must never contain a submit button.');
        $this->assertStringNotContainsString("type='submit'", $html);
    }

    public function test_the_embeddable_component_posts_no_stray_fields_with_the_bill(): void
    {
        $html = $this->renderWidget();

        preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $html, $matches);

        foreach ($matches[0] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bname=/i',
                $field,
                "A named field would be posted with the checkout form: {$field}"
            );
        }
    }

    public function test_every_button_in_the_embeddable_component_is_explicitly_type_button(): void
    {
        $html = $this->renderWidget();

        preg_match_all('/<button\b[^>]*>/i', $html, $matches);

        $this->assertNotEmpty($matches[0], 'The widget needs at least a visible trigger button.');

        foreach ($matches[0] as $button) {
            $this->assertMatchesRegularExpression(
                '/\btype=(["\'])button\1/i',
                $button,
                "Button without an explicit type=\"button\": {$button}"
            );
        }
    }

    public function test_the_embeddable_component_uses_no_blocking_dialogs_or_global_scripts(): void
    {
        $html = $this->renderWidget();

        foreach (['window.confirm', 'window.alert', 'confirm(', 'alert(', '<script'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "The widget must not contain '{$forbidden}'.");
        }
    }

    public function test_the_embeddable_component_renders_every_group_and_entry(): void
    {
        $html = $this->renderWidget();

        foreach (CounterScripts::all() as $group) {
            $this->assertStringContainsString(e($group['title']), $html);

            foreach ($group['entries'] as $entry) {
                $this->assertStringContainsString(e($entry['title']), $html);
            }
        }
    }

    public function test_the_scripts_page_and_the_widget_share_one_source_of_truth(): void
    {
        $page = (string) $this->get(route('scripts.index'))->assertOk()->getContent();
        $widget = $this->renderWidget();

        foreach (CounterScripts::all() as $group) {
            foreach ($group['entries'] as $entry) {
                $this->assertStringContainsString(e($entry['title']), $page);
                $this->assertStringContainsString(e($entry['title']), $widget);
            }
        }
    }

    private function renderWidget(): string
    {
        return (string) $this->blade('<x-counter-scripts />');
    }

    /** All searchable text of one group, lower-cased. */
    private function groupText(string $key): string
    {
        $group = CounterScripts::group($key);

        $this->assertIsArray($group, "Unknown script group '{$key}'.");

        $parts = [$group['title'], $group['blurb']];

        foreach ($group['entries'] as $entry) {
            $parts[] = $entry['title'];
            $parts[] = $entry['note'] ?? '';
            $parts = array_merge($parts, $entry['lines']);
        }

        return strtolower(implode(' ', $parts));
    }
}
