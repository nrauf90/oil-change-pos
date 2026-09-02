<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\PaymentMethod;
use App\Models\Central\Shop;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TenantFileIsolationTest extends TestCase
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

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-file-isolation-');
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
        $this->shopA = $this->createActiveTenant('file-shop-a');
        $this->shopB = $this->createActiveTenant('file-shop-b');
    }

    protected function tearDown(): void
    {
        try {
            Str::createRandomStringsNormally();
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
     * Regression caught: global evidence directories let equal generated names
     * overwrite another shop's bill or receipt and serve the replacement bytes.
     */
    public function test_same_named_supplier_evidence_uploads_and_downloads_stay_under_each_shop_uuid(): void
    {
        Storage::fake('local');
        $supplierAId = $this->seedSupplier($this->shopA, 'Tenant A owner');
        $supplierBId = $this->seedSupplier($this->shopB, 'Tenant B owner');
        $billA = $this->imageWithHash('shared-bill.jpg', 'a');
        $billB = $this->imageWithHash('shared-bill.jpg', 'a');
        $receiptA = $this->imageWithHash('shared-receipt.png', 'b');
        $receiptB = $this->imageWithHash('shared-receipt.png', 'b');

        $this->loginTo($this->shopA);
        $this->post($this->tenantUrl($this->shopA, '/suppliers/'.$supplierAId.'/supplies'), [
            'received_at' => now()->toDateString(),
            'items_received' => 'Tenant A filters',
            'total_amount' => '5000.00',
            'bill_image' => $billA,
        ])->assertRedirect();
        $supplyA = $this->tenantSupplyEvidence($this->shopA);
        $this->post($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$supplierAId.'/supplies/'.$supplyA['supply_id'].'/payments',
        ), [
            'amount' => '1000.00',
            'method' => PaymentMethod::Online->value,
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'receipt_image' => $receiptA,
        ])->assertRedirect();
        $paymentA = $this->tenantPaymentEvidence($this->shopA);
        $this->logoutFrom($this->shopA);

        $this->loginTo($this->shopB);
        $this->post($this->tenantUrl($this->shopB, '/suppliers/'.$supplierBId.'/supplies'), [
            'received_at' => now()->toDateString(),
            'items_received' => 'Tenant B filters',
            'total_amount' => '5000.00',
            'bill_image' => $billB,
        ])->assertRedirect();
        $supplyB = $this->tenantSupplyEvidence($this->shopB);
        $this->post($this->tenantUrl(
            $this->shopB,
            '/suppliers/'.$supplierBId.'/supplies/'.$supplyB['supply_id'].'/payments',
        ), [
            'amount' => '1000.00',
            'method' => PaymentMethod::Online->value,
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'receipt_image' => $receiptB,
        ])->assertRedirect();
        $paymentB = $this->tenantPaymentEvidence($this->shopB);

        $this->assertSame($supplyA['supply_id'], $supplyB['supply_id']);
        $this->assertSame($paymentA['payment_id'], $paymentB['payment_id']);
        $this->assertSame(
            'tenants/'.$this->shopA->getKey().'/supplier-bills/'.str_repeat('a', 40).'.jpg',
            $supplyA['path'],
        );
        $this->assertSame(
            'tenants/'.$this->shopB->getKey().'/supplier-bills/'.str_repeat('a', 40).'.jpg',
            $supplyB['path'],
        );
        $this->assertSame(
            'tenants/'.$this->shopA->getKey().'/supplier-payment-receipts/'.str_repeat('b', 40).'.png',
            $paymentA['path'],
        );
        $this->assertSame(
            'tenants/'.$this->shopB->getKey().'/supplier-payment-receipts/'.str_repeat('b', 40).'.png',
            $paymentB['path'],
        );

        Storage::disk('local')->put($supplyA['path'], 'tenant-a-bill');
        Storage::disk('local')->put($supplyB['path'], 'tenant-b-bill');
        Storage::disk('local')->put($paymentA['path'], 'tenant-a-receipt');
        Storage::disk('local')->put($paymentB['path'], 'tenant-b-receipt');

        $billResponse = $this->get($this->tenantUrl(
            $this->shopB,
            '/suppliers/'.$supplierBId.'/supplies/'.$supplyB['supply_id'].'/bill',
        ))->assertOk();
        $this->assertSame('tenant-b-bill', $billResponse->streamedContent());
        $receiptResponse = $this->get($this->tenantUrl(
            $this->shopB,
            '/suppliers/'.$supplierBId.'/supplies/'.$supplyB['supply_id'].'/payments/'.$paymentB['payment_id'].'/receipt',
        ))->assertOk();
        $this->assertSame('tenant-b-receipt', $receiptResponse->streamedContent());
        $this->logoutFrom($this->shopB);

        $this->loginTo($this->shopA);
        $billResponse = $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$supplierAId.'/supplies/'.$supplyA['supply_id'].'/bill',
        ))->assertOk();
        $this->assertSame('tenant-a-bill', $billResponse->streamedContent());
        $receiptResponse = $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$supplierAId.'/supplies/'.$supplyA['supply_id'].'/payments/'.$paymentA['payment_id'].'/receipt',
        ))->assertOk();
        $this->assertSame('tenant-a-receipt', $receiptResponse->streamedContent());
    }

    /**
     * Regression caught: accepting any tenants/* path lets one tenant read or
     * delete a foreign file, or fall back to the old global path by basename.
     */
    public function test_foreign_shop_paths_cannot_be_read_deleted_or_fallen_back_to_global_storage(): void
    {
        Storage::fake('local');
        $pathA = $this->seedTenant(
            $this->shopA,
            static fn (): string => app(TenantStoragePath::class)->path('supplier-bills/shared.jpg'),
        );
        $pathB = $this->seedTenant(
            $this->shopB,
            static fn (): string => app(TenantStoragePath::class)->path('supplier-bills/shared.jpg'),
        );
        Storage::disk('local')->put($pathA, 'tenant-a-file');
        Storage::disk('local')->put($pathB, 'tenant-b-file');
        Storage::disk('local')->put('supplier-bills/shared.jpg', 'legacy-global-file');

        $result = $this->seedTenant($this->shopB, static function () use ($pathA, $pathB): array {
            $paths = app(TenantStoragePath::class);

            return [
                'foreign_read' => $paths->readablePath($pathA, 'supplier-bills'),
                'foreign_delete' => $paths->delete($pathA, 'supplier-bills'),
                'legacy_delete' => $paths->delete('supplier-bills/shared.jpg', 'supplier-bills'),
                'own_read' => $paths->readablePath($pathB, 'supplier-bills'),
                'own_delete' => $paths->delete($pathB, 'supplier-bills'),
            ];
        });

        $this->assertNull($result['foreign_read']);
        $this->assertFalse($result['foreign_delete']);
        $this->assertFalse($result['legacy_delete']);
        $this->assertSame($pathB, $result['own_read']);
        $this->assertTrue($result['own_delete']);
        Storage::disk('local')->assertExists($pathA);
        Storage::disk('local')->assertMissing($pathB);
        Storage::disk('local')->assertExists('supplier-bills/shared.jpg');
    }

    public function test_same_unscoped_legacy_bill_path_is_denied_to_both_tenants(): void
    {
        Storage::fake('local');
        $legacyPath = 'supplier-bills/ambiguous-bill.jpg';
        Storage::disk('local')->put($legacyPath, 'ambiguous-legacy-bytes');
        $evidenceA = $this->seedLegacyBill($this->shopA, 'Tenant A owner', $legacyPath);
        $evidenceB = $this->seedLegacyBill($this->shopB, 'Tenant B owner', $legacyPath);

        $this->loginTo($this->shopA);
        $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$evidenceA['supplier_id'].'/supplies/'.$evidenceA['supply_id'].'/bill',
        ))->assertNotFound();
        $this->logoutFrom($this->shopA);

        $this->loginTo($this->shopB);
        $this->get($this->tenantUrl(
            $this->shopB,
            '/suppliers/'.$evidenceB['supplier_id'].'/supplies/'.$evidenceB['supply_id'].'/bill',
        ))->assertNotFound();

        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertMissing('tenants/'.$this->shopA->getKey().'/'.$legacyPath);
        Storage::disk('local')->assertMissing('tenants/'.$this->shopB->getKey().'/'.$legacyPath);
    }

    public function test_pre_migrated_legacy_bill_copy_is_served_only_to_its_tenant(): void
    {
        Storage::fake('local');
        $legacyPath = 'supplier-bills/legacy-bill.jpg';
        $scopedPathA = 'tenants/'.$this->shopA->getKey().'/'.$legacyPath;
        $scopedPathB = 'tenants/'.$this->shopB->getKey().'/'.$legacyPath;
        Storage::disk('local')->put($legacyPath, 'untrusted-global-bytes');
        Storage::disk('local')->put($scopedPathA, 'tenant-a-migrated-bytes');
        $evidenceA = $this->seedLegacyBill($this->shopA, 'Tenant A owner', $legacyPath);
        $evidenceB = $this->seedLegacyBill($this->shopB, 'Tenant B owner', $legacyPath);

        $this->loginTo($this->shopA);
        $response = $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$evidenceA['supplier_id'].'/supplies/'.$evidenceA['supply_id'].'/bill',
        ))->assertOk();

        $this->assertSame('tenant-a-migrated-bytes', $response->streamedContent());
        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($scopedPathA);
        Storage::disk('local')->assertMissing($scopedPathB);
        $this->logoutFrom($this->shopA);

        $this->loginTo($this->shopB);
        $this->get($this->tenantUrl(
            $this->shopB,
            '/suppliers/'.$evidenceB['supplier_id'].'/supplies/'.$evidenceB['supply_id'].'/bill',
        ))->assertNotFound();
    }

    public function test_pre_migrated_legacy_receipt_never_falls_back_to_the_global_source(): void
    {
        Storage::fake('local');
        $legacyPath = 'supplier-payment-receipts/legacy-receipt.png';
        $scopedPath = 'tenants/'.$this->shopA->getKey().'/'.$legacyPath;
        Storage::disk('local')->put($legacyPath, 'untrusted-global-bytes');
        Storage::disk('local')->put($scopedPath, 'tenant-a-migrated-bytes');
        $evidence = $this->seedLegacyReceipt($this->shopA, 'Tenant A owner', $legacyPath);

        $this->loginTo($this->shopA);
        $response = $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$evidence['supplier_id'].'/supplies/'.$evidence['supply_id'].'/payments/'.$evidence['payment_id'].'/receipt',
        ))->assertOk();

        $this->assertSame('tenant-a-migrated-bytes', $response->streamedContent());
        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($scopedPath);

        Storage::disk('local')->delete($scopedPath);
        $this->get($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$evidence['supplier_id'].'/supplies/'.$evidence['supply_id'].'/payments/'.$evidence['payment_id'].'/receipt',
        ))->assertNotFound();

        Storage::disk('local')->assertExists($legacyPath);
    }

    public function test_failed_payment_write_deletes_the_new_tenant_scoped_receipt(): void
    {
        Storage::fake('local');
        $evidence = $this->seedTenant($this->shopA, function (): array {
            $owner = $this->createOwner('Tenant A owner');
            $supply = Supply::factory()->create(['total_amount' => '1000.00']);
            SupplierPayment::factory()
                ->for($supply)
                ->for($owner)
                ->create(['amount' => '900.00']);

            return [
                'supplier_id' => $supply->supplier_id,
                'supply_id' => $supply->getKey(),
            ];
        });
        $receipt = $this->imageWithHash('failed-receipt.png', 'c');
        $expectedPath = 'tenants/'.$this->shopA->getKey()
            .'/supplier-payment-receipts/'.str_repeat('c', 40).'.png';

        $this->loginTo($this->shopA);
        $this->post($this->tenantUrl(
            $this->shopA,
            '/suppliers/'.$evidence['supplier_id'].'/supplies/'.$evidence['supply_id'].'/payments',
        ), [
            'amount' => '200.00',
            'method' => PaymentMethod::Online->value,
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'receipt_image' => $receipt,
        ])->assertSessionHasErrors('amount');

        Storage::disk('local')->assertMissing($expectedPath);
        $this->assertSame(1, $this->seedTenant(
            $this->shopA,
            static fn (): int => SupplierPayment::query()->count(),
        ));
    }

    #[DataProvider('unsafeStoredPaths')]
    public function test_traversal_and_confusable_prefix_paths_cannot_be_read_or_deleted(string $storedPath): void
    {
        Storage::fake('local');

        $result = $this->seedTenant($this->shopB, static function () use ($storedPath): array {
            $paths = app(TenantStoragePath::class);

            return [
                'read' => $paths->readablePath($storedPath, 'supplier-bills'),
                'delete' => $paths->delete($storedPath, 'supplier-bills'),
            ];
        });

        $this->assertNull($result['read']);
        $this->assertFalse($result['delete']);
    }

    /** @return array<string, array{string}> */
    public static function unsafeStoredPaths(): array
    {
        return [
            'absolute' => ['/supplier-bills/secret.jpg'],
            'windows separator' => ['supplier-bills\secret.jpg'],
            'null byte' => ["supplier-bills/secret\0.jpg"],
            'empty segment' => ['supplier-bills//secret.jpg'],
            'dot segment' => ['supplier-bills/./secret.jpg'],
            'parent segment' => ['supplier-bills/../secret.jpg'],
            'legacy sibling prefix' => ['supplier-bills-archive/secret.jpg'],
            'foreign tenant prefix' => ['tenants/foreign/supplier-bills/secret.jpg'],
        ];
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

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function seedTenant(Shop $shop, callable $callback): mixed
    {
        return $this->manager->within($shop, $callback);
    }

    private function seedSupplier(Shop $shop, string $ownerName): int
    {
        return $this->seedTenant($shop, function () use ($ownerName): int {
            $this->createOwner($ownerName);

            return Supplier::factory()->create()->getKey();
        });
    }

    /** @return array{supplier_id: int, supply_id: int, path: string} */
    private function tenantSupplyEvidence(Shop $shop): array
    {
        return $this->seedTenant($shop, static function (): array {
            $supply = Supply::query()->sole();

            return [
                'supplier_id' => $supply->supplier_id,
                'supply_id' => $supply->getKey(),
                'path' => (string) $supply->bill_image_path,
            ];
        });
    }

    /** @return array{payment_id: int, path: string} */
    private function tenantPaymentEvidence(Shop $shop): array
    {
        return $this->seedTenant($shop, static function (): array {
            $payment = SupplierPayment::query()->sole();

            return [
                'payment_id' => $payment->getKey(),
                'path' => (string) $payment->receipt_image_path,
            ];
        });
    }

    /** @return array{supplier_id: int, supply_id: int} */
    private function seedLegacyBill(Shop $shop, string $ownerName, string $path): array
    {
        return $this->seedTenant($shop, function () use ($ownerName, $path): array {
            $this->createOwner($ownerName);
            $supply = Supply::factory()->create(['bill_image_path' => $path]);

            return [
                'supplier_id' => $supply->supplier_id,
                'supply_id' => $supply->getKey(),
            ];
        });
    }

    /** @return array{supplier_id: int, supply_id: int, payment_id: int} */
    private function seedLegacyReceipt(Shop $shop, string $ownerName, string $path): array
    {
        return $this->seedTenant($shop, function () use ($ownerName, $path): array {
            $owner = $this->createOwner($ownerName);
            $supply = Supply::factory()->create();
            $payment = SupplierPayment::factory()
                ->for($supply)
                ->for($owner)
                ->create(['receipt_image_path' => $path]);

            return [
                'supplier_id' => $supply->supplier_id,
                'supply_id' => $supply->getKey(),
                'payment_id' => $payment->getKey(),
            ];
        });
    }

    private function createOwner(string $name): User
    {
        return User::factory()->admin()->create([
            'name' => $name,
            'username' => 'owner',
            'password' => 'password',
        ]);
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
        return 'https://'.$shop->slug.'.pos.example.test/'.ltrim($path, '/');
    }

    private function imageWithHash(string $filename, string $character): UploadedFile
    {
        $file = UploadedFile::fake()->image($filename);
        Str::createRandomStringsUsing(
            static fn (int $length): string => str_repeat($character, $length),
        );
        $file->hashName();
        Str::createRandomStringsNormally();

        return $file;
    }

    private function assertTenantStateIsRevoked(): void
    {
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant file isolation test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
