<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Central\Shop;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TenantExportIsolationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private TenantConnectionManager $manager;

    private Shop $shopA;

    private Shop $shopB;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-export-isolation-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('app.url', 'https://pos.example.test');
        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->tenantRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createActiveTenant('export-shop-a');
        $this->shopB = $this->createActiveTenant('export-shop-b');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            DB::purge('central');

            foreach ($this->temporaryDirectories as $temporaryDirectory) {
                File::deleteDirectory($temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    /**
     * Regression caught: resolving B's PDF request to the first active shop
     * returns A's colliding invoice filename and reads A's sale and line rows.
     */
    public function test_colliding_sale_pdf_download_only_exports_the_active_tenant_invoice(): void
    {
        $saleA = $this->seedSale($this->shopA, 'Tenant A export line', 'TENANT-A-EXPORT');
        $saleB = $this->seedSale($this->shopB, 'Tenant B export line', 'TENANT-B-EXPORT');

        $this->assertSame($saleA['sale_id'], $saleB['sale_id']);
        $this->assertSame($saleA['line_id'], $saleB['line_id']);
        $this->loginTo($this->shopB);

        $pdfB = $this->get($this->tenantUrl($this->shopB, "/sales/{$saleB['sale_id']}/pdf"))
            ->assertOk()
            ->assertDownload('TENANT-B-EXPORT.pdf')
            ->assertHeader('content-type', 'application/pdf');
        $this->assertTenantStateIsRevoked();
        $pdfBText = $this->extractPdfText($pdfB->getContent());
        $this->assertStringContainsString('TENANT-B-EXPORT', $pdfBText);
        $this->assertStringContainsString('TENANT-B-EXPORT customer', $pdfBText);
        $this->assertStringContainsString('Tenant B export line', $pdfBText);
        $this->assertStringNotContainsString('TENANT-A-EXPORT', $pdfBText);
        $this->assertStringNotContainsString('Tenant A export line', $pdfBText);

        $this->logoutFrom($this->shopB);
        $this->loginTo($this->shopA);
        $pdfA = $this->get($this->tenantUrl($this->shopA, "/sales/{$saleA['sale_id']}/pdf"))
            ->assertOk()
            ->assertDownload('TENANT-A-EXPORT.pdf')
            ->assertHeader('content-type', 'application/pdf');
        $this->assertTenantStateIsRevoked();
        $pdfAText = $this->extractPdfText($pdfA->getContent());
        $this->assertStringContainsString('TENANT-A-EXPORT', $pdfAText);
        $this->assertStringContainsString('TENANT-A-EXPORT customer', $pdfAText);
        $this->assertStringContainsString('Tenant A export line', $pdfAText);
        $this->assertStringNotContainsString('TENANT-B-EXPORT', $pdfAText);
        $this->assertStringNotContainsString('Tenant B export line', $pdfAText);

        $this->assertSame(
            ['TENANT-A-EXPORT', 'Tenant A export line'],
            $this->tenantSaleState($this->shopA, $saleA['sale_id']),
        );
        $this->assertSame(
            ['TENANT-B-EXPORT', 'Tenant B export line'],
            $this->tenantSaleState($this->shopB, $saleB['sale_id']),
        );
    }

    private function createActiveTenant(string $slug): Shop
    {
        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();

        return $shop;
    }

    /** @return array{sale_id: int, line_id: int} */
    private function seedSale(Shop $shop, string $lineName, string $invoiceNumber): array
    {
        return $this->manager->within($shop, static function () use ($lineName, $invoiceNumber): array {
            $owner = User::factory()->admin()->create([
                'name' => 'Export owner',
                'username' => 'owner',
                'password' => 'password',
            ]);
            $sale = Sale::factory()->create([
                'cashier_id' => $owner->getKey(),
                'invoice_number' => $invoiceNumber,
                'customer_name' => $invoiceNumber.' customer',
                'total_amount' => 875,
            ]);
            $line = SaleItem::factory()->for($sale)->create([
                'item_name' => $lineName,
                'manually_charged_price' => 875,
            ]);

            return ['sale_id' => $sale->getKey(), 'line_id' => $line->getKey()];
        });
    }

    private function loginTo(Shop $shop): void
    {
        $this->post($this->tenantUrl($shop, '/login'), [
            'username' => 'owner',
            'password' => 'password',
        ])->assertRedirect();
        $this->assertTenantStateIsRevoked();
    }

    private function logoutFrom(Shop $shop): void
    {
        $this->post($this->tenantUrl($shop, '/logout'))->assertRedirect();
        $this->assertTenantStateIsRevoked();
        $this->flushSession();
    }

    private function tenantUrl(Shop $shop, string $path): string
    {
        return "https://{$shop->slug}.pos.example.test/".ltrim($path, '/');
    }

    /** @return array{string, string} */
    private function tenantSaleState(Shop $shop, int $saleId): array
    {
        return $this->manager->within($shop, static function () use ($saleId): array {
            $sale = Sale::query()->with('lines')->findOrFail($saleId);

            return [$sale->invoice_number, $sale->lines->sole()->item_name];
        });
    }

    private function assertTenantStateIsRevoked(): void
    {
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
    }

    private function extractPdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = [];

        foreach ($streams[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $content = $inflated === false ? $stream : $inflated;
            $text[] = str_replace("\0", '', $content);
        }

        return implode("\n", $text);
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant export isolation test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
