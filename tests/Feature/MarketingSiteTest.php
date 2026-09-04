<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingSiteTest extends TestCase
{
    /**
     * The marketing site is the platform's shop window and belongs to no shop,
     * so it opts out of the tenant scaffolding the rest of the suite sets up.
     */
    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    #[Test]
    public function it_renders_the_public_marketing_site(): void
    {
        $response = $this->get(route('marketing.home'));

        $response->assertOk();
        $response->assertSee('MNR IT Solutions', escape: false);
        $response->assertSee('Selected products', escape: false);
    }

    #[Test]
    public function it_lists_every_product_and_both_build_services(): void
    {
        $response = $this->get(route('marketing.home'));

        foreach (['MNR POS', 'MNR HRM', 'MNR CRM', 'MNR ERP'] as $product) {
            $response->assertSee($product, escape: false);
        }

        $response->assertSee('Custom web', escape: false);
        $response->assertSee('Mobile app', escape: false);
    }

    #[Test]
    public function it_shows_the_configured_contact_details(): void
    {
        config()->set('marketing.email', 'sales@example.test');
        config()->set('marketing.whatsapp', '+92 300 1234567');

        $response = $this->get(route('marketing.home'));

        $response->assertSee('sales@example.test', escape: false);
        $response->assertSee('+92 300 1234567', escape: false);
        // wa.me accepts bare digits only — no plus sign, spaces or dashes.
        $response->assertSee('https://wa.me/923001234567', escape: false);
    }

    #[Test]
    public function it_makes_no_claim_it_cannot_back_up(): void
    {
        $body = $this->get(route('marketing.home'))->getContent();

        // Invented client counts, awards and testimonials are the easiest thing
        // to reach for on a page like this and the hardest to walk back.
        $this->assertDoesNotMatchRegularExpression('/\d[\d,]*\+?\s*(happy\s+)?(clients|customers)/i', $body);
        $this->assertStringNotContainsStringIgnoringCase('award-winning', $body);
        $this->assertStringNotContainsStringIgnoringCase('testimonial', $body);
        // Sample figures are shown, so they have to be labelled as samples.
        $this->assertStringContainsString('illustrative sample data', $body);
    }

    #[Test]
    public function it_never_opens_a_tenant_database_connection(): void
    {
        $connections = [];

        DB::listen(function ($query) use (&$connections): void {
            $connections[] = $query->connectionName;
        });

        $this->get(route('marketing.home'))->assertOk();

        $this->assertNotContains('tenant', $connections);
    }
}
