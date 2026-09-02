<?php

namespace Tests\Feature\Platform;

use App\Actions\Tenancy\RotateShopDatabaseEndpoint;
use App\Enums\ShopLifecycleEvent;
use App\Enums\TenantDatabaseEndpointMarkerState;
use App\Exceptions\TenantDatabaseEndpointRotationException;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\ReconcileTenantDatabaseEndpointMarker;
use App\Tenancy\TenantDatabaseEndpointConnectionRunner;
use App\Tenancy\TenantDatabaseEndpointMarkerReconciler;
use App\Tenancy\ValidatedTenantConnection;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ShopDatabaseEndpointRotationTest extends PlatformTestCase
{
    public function test_preview_returns_old_and_new_normalized_targets_without_credentials(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Managed DNS Shop',
            slug: 'managed-dns-shop',
            databaseDriver: 'mysql',
            databaseName: 'managed_dns_shop',
            databaseHost: 'database.example.test',
            databaseUsername: 'rotation_user',
            databasePassword: 'super-secret-rotation-password',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->resolveDatabaseHostTo('8.8.8.8');

        $preview = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);

        $this->assertSame(false, $preview['pending']);
        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'managed_dns_shop',
            'hosts' => ['database.example.test'],
            'port' => 3306,
            'fingerprint' => $oldFingerprint,
            'endpoint_claim_fingerprints' => $oldClaims,
        ], $preview['old_target']);
        $this->assertSame('mysql', $preview['new_target']['driver']);
        $this->assertSame('managed_dns_shop', $preview['new_target']['database']);
        $this->assertSame(['database.example.test'], $preview['new_target']['hosts']);
        $this->assertSame(3306, $preview['new_target']['port']);
        $this->assertSame([[
            'host' => 'database.example.test',
            'address' => '8.8.8.8',
            'port' => 3306,
        ]], $preview['new_target']['endpoints']);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            $preview['new_target']['fingerprint'],
        );
        $this->assertNotSame($oldFingerprint, $preview['new_target']['fingerprint']);
        $this->assertSame(
            ['driver', 'database', 'hosts', 'port', 'fingerprint', 'endpoints'],
            array_keys($preview['new_target']),
        );

        $surface = json_encode($preview, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('rotation_user', $surface);
        $this->assertStringNotContainsString('super-secret-rotation-password', $surface);
        $this->assertStringNotContainsString((string) $shop->database_attestation_key, $surface);
    }

    public function test_preview_rejects_an_actor_deactivated_after_authentication(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Deactivated Actor Shop',
            slug: 'deactivated-actor-shop',
            databaseDriver: 'mysql',
            databaseName: 'deactivated_actor_shop',
            databaseHost: 'private-managed-database.example.test',
            databaseUsername: 'do-not-disclose-user',
            databasePassword: 'do-not-disclose-password',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        DB::connection('central')->table('platform_users')
            ->where('id', $actor->getKey())
            ->update(['is_active' => false]);
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
            $this->fail('A stale deactivated platform actor was authorized to preview rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_UNAUTHORIZED', $exception->errorCode);
            $this->assertSame(
                'Active super administrator access is required.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private-managed-database', $exception->getMessage());
            $this->assertStringNotContainsString('do-not-disclose', $exception->getMessage());
        }

        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    #[DataProvider('forbiddenEndpointAddresses')]
    public function test_preview_rejects_a_forbidden_endpoint_without_mutation(string $address): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Forbidden Endpoint Shop',
            slug: 'forbidden-endpoint-shop-'.str_replace('.', '-', $address),
            databaseDriver: 'mysql',
            databaseName: 'forbidden_endpoint_shop',
            databaseHost: 'forbidden-endpoint.example.test',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->resolveDatabaseHostTo($address);

        try {
            app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
            $this->fail('A forbidden endpoint was offered for rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_DESTINATION_FORBIDDEN', $exception->errorCode);
            $this->assertSame(
                'The resolved database destination is not permitted.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($address, $exception->getMessage());
        }

        $persistedShop = $shop->fresh();

        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
    }

    public function test_preview_rejects_an_endpoint_claimed_by_another_shop(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Original Endpoint Shop',
            slug: 'original-endpoint-shop',
            databaseDriver: 'mysql',
            databaseName: 'shared_collision_schema',
            databaseHost: 'original-database.example.test',
        );
        $shop->markActive();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->resolveDatabaseHostTo('8.8.8.8');
        $otherShop = Shop::registerForProvisioning(
            name: 'Claim Owner Shop',
            slug: 'claim-owner-shop',
            databaseDriver: 'mysql',
            databaseName: 'shared_collision_schema',
            databaseHost: 'other-database.example.test',
        );

        try {
            app(RotateShopDatabaseEndpoint::class)->preview(
                PlatformUser::factory()->create(),
                $shop,
            );
            $this->fail('A database endpoint claimed by another shop was offered for rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_DESTINATION_ASSIGNED', $exception->errorCode);
            $this->assertSame(
                'The resolved database destination is assigned to another shop.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($oldFingerprint, $shop->fresh()->database_target_fingerprint);
        $this->assertSame($oldClaims, $shop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $shop->lifecycleActivities()->count());
        $this->assertSame(1, $otherShop->databaseTargetClaims()->count());
    }

    public function test_preview_rejects_an_unchanged_dns_endpoint(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Stable DNS Shop',
            slug: 'stable-dns-shop',
            databaseDriver: 'mysql',
            databaseName: 'stable_dns_shop',
            databaseHost: 'stable-database.example.test',
        );
        $shop->markActive();

        try {
            app(RotateShopDatabaseEndpoint::class)->preview(
                PlatformUser::factory()->create(),
                $shop,
            );
            $this->fail('An unchanged DNS endpoint was offered for rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_NOT_REQUIRED', $exception->errorCode);
            $this->assertSame(
                'The registered database endpoint still matches the current DNS result.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_preview_rejects_a_literal_ip_target(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Literal Address Shop',
            slug: 'literal-address-shop',
            databaseDriver: 'mysql',
            databaseName: 'literal_address_shop',
            databaseHost: '1.1.1.1',
        );
        $shop->markActive();

        try {
            app(RotateShopDatabaseEndpoint::class)->preview(
                PlatformUser::factory()->create(),
                $shop,
            );
            $this->fail('A literal database address was offered as a DNS rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_DNS_REQUIRED', $exception->errorCode);
            $this->assertSame(
                'Endpoint rotation requires a registered DNS hostname.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_confirmed_rotation_reconciles_central_claims_marker_and_actor_audit(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Confirmed Rotation Shop',
            slug: 'confirmed-rotation-shop',
            databaseDriver: 'mysql',
            databaseName: 'confirmed_rotation_shop',
            databaseHost: 'confirmed-database.example.test',
            databaseUsername: 'preserved_rotation_user',
            databasePassword: 'preserved-rotation-password',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create(['name' => 'Rotation Administrator']);
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $marker = new class((string) $shop->getKey(), $oldFingerprint, $shop->databaseAttestationHmac()) implements TenantDatabaseEndpointMarkerReconciler
        {
            public ?string $observedShopId = null;

            public ?string $observedOldFingerprint = null;

            public string $markerFingerprint;

            public string $markerHmac;

            public function __construct(
                private readonly string $shopId,
                string $markerFingerprint,
                string $markerHmac,
            ) {
                $this->markerFingerprint = $markerFingerprint;
                $this->markerHmac = $markerHmac;
            }

            public function reconcile(
                Shop $shop,
                ValidatedTenantConnection $candidate,
                #[\SensitiveParameter]
                string $oldFingerprint,
                #[\SensitiveParameter]
                string $oldMarkerHmac,
                Closure $afterMarkerVerified,
            ): void {
                $this->observedShopId = (string) $shop->getKey();
                $this->observedOldFingerprint = $oldFingerprint;
                $state = $this->markerFingerprint === $oldFingerprint
                    && $this->markerHmac === $oldMarkerHmac
                    ? TenantDatabaseEndpointMarkerState::Old
                    : TenantDatabaseEndpointMarkerState::New;

                $afterMarkerVerified($state);

                $this->markerFingerprint = $candidate->target()->fingerprint;
                $this->markerHmac = $candidate->expectedMarkerHmac();
            }
        };
        app()->instance(TenantDatabaseEndpointMarkerReconciler::class, $marker);
        $this->resolveDatabaseHostTo('8.8.8.8');

        $result = app(RotateShopDatabaseEndpoint::class)->handle(
            $actor,
            $shop,
            'confirmed-rotation-shop',
        );

        $persistedShop = $shop->fresh();
        $newFingerprint = $result['new_target']['fingerprint'];
        $activities = $persistedShop->lifecycleActivities()
            ->get();
        $started = $activities->firstWhere(
            'event',
            ShopLifecycleEvent::DatabaseEndpointRotationStarted,
        );
        $completed = $activities->firstWhere(
            'event',
            ShopLifecycleEvent::DatabaseEndpointRotationCompleted,
        );

        $this->assertSame($newFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertNotSame($oldFingerprint, $newFingerprint);
        $this->assertSame('preserved_rotation_user', $persistedShop->database_username);
        $this->assertSame('preserved-rotation-password', $persistedShop->database_password);
        $this->assertSame('active', $persistedShop->status->value);
        $this->assertSame(1, $persistedShop->databaseTargetClaims()->count());
        $this->assertNotSame(
            $oldClaims,
            $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all(),
        );
        $this->assertSame((string) $shop->getKey(), $marker->observedShopId);
        $this->assertSame($oldFingerprint, $marker->observedOldFingerprint);
        $this->assertSame($newFingerprint, $marker->markerFingerprint);
        $this->assertSame($persistedShop->databaseAttestationHmac(), $marker->markerHmac);
        $this->assertCount(2, $activities);
        $this->assertNotNull($started);
        $this->assertNotNull($completed);
        $this->assertSame($actor->getKey(), $started->platform_user_id);
        $this->assertSame($actor->getKey(), $completed->platform_user_id);
        $this->assertSame('Rotation Administrator', $started->actor_name);
        $this->assertSame($started->metadata, $completed->metadata);
        $this->assertSame($oldFingerprint, $started->metadata['old_target_fingerprint']);
        $this->assertSame($newFingerprint, $started->metadata['new_target_fingerprint']);
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $started->metadata['rotation_id'],
        );
    }

    public function test_rotation_requires_the_exact_shop_slug_before_any_mutation(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Confirmation Shop',
            slug: 'confirmation-shop',
            databaseDriver: 'mysql',
            databaseName: 'confirmation_shop',
            databaseHost: 'confirmation-database.example.test',
        );
        $shop->markActive();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                PlatformUser::factory()->create(),
                $shop,
                'Confirmation-Shop',
            );
            $this->fail('A rotation without exact deliberate confirmation was accepted.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_CONFIRMATION_INVALID', $exception->errorCode);
            $this->assertSame(
                'Type the exact shop slug to confirm database endpoint rotation.',
                $exception->getMessage(),
            );
        }

        $persistedShop = $shop->fresh();

        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
    }

    public function test_retry_recovers_old_marker_after_central_rotation_without_duplicate_start(): void
    {
        [$shop, $actor, $marker, $oldFingerprint] = $this->rotationScenario(
            'old-marker-recovery',
        );
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
            $this->fail('The simulated interruption after central rotation did not fail.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $afterInterruption = $shop->fresh();
        $newFingerprint = (string) $afterInterruption->database_target_fingerprint;

        $this->assertNotSame($oldFingerprint, $newFingerprint);
        $this->assertSame($oldFingerprint, $marker->markerFingerprint);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(0, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));

        $marker->failAfterCentral = false;
        $result = app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);

        $this->assertTrue($result['pending']);
        $this->assertSame($newFingerprint, $marker->markerFingerprint);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));
        $this->assertSame(
            $shop->lifecycleActivities()
                ->where('event', 'tenant.database_endpoint_rotation_started')
                ->value('metadata->rotation_id'),
            $shop->lifecycleActivities()
                ->where('event', 'tenant.database_endpoint_rotation_completed')
                ->value('metadata->rotation_id'),
        );
    }

    public function test_retry_completes_a_new_marker_with_missing_completion_audit(): void
    {
        [$shop, $actor, $marker, $oldFingerprint] = $this->rotationScenario(
            'new-marker-recovery',
        );
        $marker->failAfterMarker = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
            $this->fail('The simulated interruption after marker rotation did not fail.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $newFingerprint = (string) $shop->fresh()->database_target_fingerprint;

        $this->assertNotSame($oldFingerprint, $newFingerprint);
        $this->assertSame($newFingerprint, $marker->markerFingerprint);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(0, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));

        $marker->failAfterMarker = false;
        $result = app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);

        $this->assertTrue($result['pending']);
        $this->assertSame($newFingerprint, $marker->markerFingerprint);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));
    }

    public function test_rotation_is_rejected_while_the_same_shop_rotation_lock_is_held(): void
    {
        [$shop, $actor] = $this->rotationScenario('serialized-rotation');
        $lock = Cache::store('database')->lock(
            'tenant-endpoint-rotation:'.$shop->getKey(),
            60,
        );
        $this->assertTrue($lock->get());
        $oldFingerprint = (string) $shop->database_target_fingerprint;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
            $this->fail('A concurrent database endpoint rotation was not serialized.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_BUSY', $exception->errorCode);
            $this->assertSame(
                'Another database endpoint rotation is already running for this shop.',
                $exception->getMessage(),
            );
        } finally {
            $lock->release();
        }

        $this->assertSame($oldFingerprint, $shop->fresh()->database_target_fingerprint);
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_production_reconciler_advances_only_the_exact_old_marker_tuple(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Production Reconciler Shop',
            slug: 'production-reconciler-shop',
            databaseDriver: 'mysql',
            databaseName: 'production_reconciler_shop',
            databaseHost: 'production-reconciler.example.test',
        );
        $shop->markActive();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->statement(<<<'SQL'
            CREATE TABLE tenant_installations (
                id INTEGER PRIMARY KEY,
                shop_id TEXT NOT NULL,
                target_fingerprint TEXT NOT NULL,
                attestation_hmac TEXT NOT NULL,
                connection_nonce TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
            SQL);
        $connection->table('tenant_installations')->insert([
            'id' => 1,
            'shop_id' => $shop->getKey(),
            'target_fingerprint' => $oldFingerprint,
            'attestation_hmac' => $shop->databaseAttestationHmac(),
        ]);
        app()->instance(
            TenantDatabaseEndpointConnectionRunner::class,
            new class($connection) implements TenantDatabaseEndpointConnectionRunner
            {
                public function __construct(private readonly Connection $connection) {}

                public function run(
                    ValidatedTenantConnection $candidate,
                    Closure $operation,
                ): mixed {
                    return $this->connection->transaction(
                        fn (): mixed => $operation($this->connection),
                    );
                }
            },
        );
        app()->bind(
            TenantDatabaseEndpointMarkerReconciler::class,
            ReconcileTenantDatabaseEndpointMarker::class,
        );
        $this->resolveDatabaseHostTo('8.8.8.8');

        $result = app(RotateShopDatabaseEndpoint::class)->handle(
            PlatformUser::factory()->create(),
            $shop,
            $shop->slug,
        );

        $marker = $connection->table('tenant_installations')->where('id', 1)->first();

        $this->assertNotNull($marker);
        $this->assertSame($shop->getKey(), $marker->shop_id);
        $this->assertSame($result['new_target']['fingerprint'], $marker->target_fingerprint);
        $this->assertSame($shop->fresh()->databaseAttestationHmac(), $marker->attestation_hmac);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));
    }

    public function test_platform_super_admin_can_review_and_confirm_the_rotation_workflow(): void
    {
        [$shop, $actor] = $this->rotationScenario('filament-rotation-workflow');
        $shop->suspend();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        Filament::setCurrentPanel(Filament::getPanels()['platform']);

        Livewire::actingAs($actor, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()])
            ->assertActionVisible('rotateDatabaseEndpoint')
            ->assertActionHasLabel('rotateDatabaseEndpoint', 'Rotate database endpoint')
            ->assertActionExists(
                'rotateDatabaseEndpoint',
                static function (Action $action) use ($oldFingerprint): bool {
                    $summary = (string) $action->getModalDescription();

                    return str_contains($summary, $oldFingerprint)
                        && str_contains($summary, '8.8.8.8');
                },
            )
            ->callAction('rotateDatabaseEndpoint', [
                'confirmation' => $shop->slug,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Database endpoint rotated');

        $this->assertNotSame($oldFingerprint, $shop->fresh()->database_target_fingerprint);
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_started'));
        $this->assertSame(1, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));
    }

    public function test_mismatched_marker_is_rejected_without_leaking_or_mutating_central_state(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Marker Conflict Shop',
            slug: 'marker-conflict-shop',
            databaseDriver: 'mysql',
            databaseName: 'marker_conflict_shop',
            databaseHost: 'secret-marker-host.example.test',
            databaseUsername: 'secret-marker-user',
            databasePassword: 'secret-marker-password',
        );
        $shop->markActive();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->bindProductionMarkerReconciler(
            'another-shop',
            $oldFingerprint,
            $shop->databaseAttestationHmac(),
        );
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                PlatformUser::factory()->create(),
                $shop,
                $shop->slug,
            );
            $this->fail('A marker owned by another shop was rotated.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_MARKER_CONFLICT', $exception->errorCode);
            $this->assertSame(
                'The tenant database marker does not match an eligible rotation state.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('secret-marker', $exception->getMessage());
            $this->assertStringNotContainsString($oldFingerprint, $exception->getMessage());
            $this->assertStringNotContainsString('8.8.8.8', $exception->getMessage());
        }

        $persistedShop = $shop->fresh();

        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
    }

    public function test_production_runner_requires_authenticated_tls_without_leaking_credentials(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'TLS Gate Shop',
            slug: 'tls-gate-shop',
            databaseDriver: 'mysql',
            databaseName: 'tls_gate_shop',
            databaseHost: 'private-tls-host.example.test',
            databaseUsername: 'private-tls-user',
            databasePassword: 'private-tls-password',
        );
        $shop->markActive();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                PlatformUser::factory()->create(),
                $shop,
                $shop->slug,
            );
            $this->fail('A production rotation opened without authenticated TLS.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_AUTHENTICATED_TLS_REQUIRED', $exception->errorCode);
            $this->assertSame(
                'Database endpoint rotation requires authenticated TLS and hostname verification.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private-tls', $exception->getMessage());
            $this->assertStringNotContainsString('8.8.8.8', $exception->getMessage());
        }

        $this->assertSame($oldFingerprint, $shop->fresh()->database_target_fingerprint);
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_tampered_pending_start_is_not_used_for_recovery(): void
    {
        [$shop, $actor, $marker] = $this->rotationScenario('tampered-pending-rotation');
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
        } catch (TenantDatabaseEndpointRotationException) {
        }

        $started = $shop->lifecycleActivities()
            ->where('event', 'tenant.database_endpoint_rotation_started')
            ->sole();
        DB::connection('central')
            ->table('shop_lifecycle_activities')
            ->where('id', $started->getKey())
            ->update([
                'metadata' => json_encode(
                    [...$started->metadata, 'unexpected' => 'forged'],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
        $marker->failAfterCentral = false;
        $centralFingerprint = (string) $shop->fresh()->database_target_fingerprint;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
            $this->fail('A tampered pending-start tuple was used for recovery.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_STATE_CONFLICT', $exception->errorCode);
            $this->assertSame(
                'The database endpoint rotation state requires operator review.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($centralFingerprint, $shop->fresh()->database_target_fingerprint);
        $this->assertSame(0, $this->rotationEventCount($shop, 'tenant.database_endpoint_rotation_completed'));
    }

    public function test_actor_is_reauthorized_after_candidate_marker_verification(): void
    {
        [$shop, $actor, $marker, $oldFingerprint] = $this->rotationScenario(
            'actor-reauthorization',
        );
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $marker->beforeCallback = static function () use ($actor): void {
            DB::connection('central')->table('platform_users')
                ->where('id', $actor->getKey())
                ->update(['is_active' => false]);
        };

        try {
            app(RotateShopDatabaseEndpoint::class)->handle($actor, $shop, $shop->slug);
            $this->fail('A deactivated actor completed an endpoint rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_UNAUTHORIZED', $exception->errorCode);
            $this->assertSame(
                'Active super administrator access is required.',
                $exception->getMessage(),
            );
        }

        $persistedShop = $shop->fresh();

        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame($oldFingerprint, $marker->markerFingerprint);
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
    }

    private function resolveDatabaseHostTo(string $address): void
    {
        app()->instance(DatabaseHostResolver::class, new class($address) implements DatabaseHostResolver
        {
            public function __construct(private readonly string $address) {}

            public function resolve(string $host): array
            {
                return [$this->address];
            }
        });
    }

    /** @return array{Shop, PlatformUser, EndpointRotationMarkerDouble, string} */
    private function rotationScenario(string $slug): array
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Rotation Recovery Shop',
            slug: $slug,
            databaseDriver: 'mysql',
            databaseName: str_replace('-', '_', $slug),
            databaseHost: $slug.'.example.test',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $marker = new EndpointRotationMarkerDouble(
            $oldFingerprint,
            $shop->databaseAttestationHmac(),
        );
        app()->instance(TenantDatabaseEndpointMarkerReconciler::class, $marker);
        $this->resolveDatabaseHostTo('8.8.8.8');

        return [$shop, $actor, $marker, $oldFingerprint];
    }

    private function rotationEventCount(Shop $shop, string $event): int
    {
        return $shop->lifecycleActivities()->where('event', $event)->count();
    }

    private function bindProductionMarkerReconciler(
        string $markerShopId,
        string $fingerprint,
        string $markerHmac,
    ): Connection {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->statement(<<<'SQL'
            CREATE TABLE tenant_installations (
                id INTEGER PRIMARY KEY,
                shop_id TEXT NOT NULL,
                target_fingerprint TEXT NOT NULL,
                attestation_hmac TEXT NOT NULL,
                connection_nonce TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )
            SQL);
        $connection->table('tenant_installations')->insert([
            'id' => 1,
            'shop_id' => $markerShopId,
            'target_fingerprint' => $fingerprint,
            'attestation_hmac' => $markerHmac,
        ]);
        app()->instance(
            TenantDatabaseEndpointConnectionRunner::class,
            new class($connection) implements TenantDatabaseEndpointConnectionRunner
            {
                public function __construct(private readonly Connection $connection) {}

                public function run(
                    ValidatedTenantConnection $candidate,
                    Closure $operation,
                ): mixed {
                    return $this->connection->transaction(
                        fn (): mixed => $operation($this->connection),
                    );
                }
            },
        );
        app()->bind(
            TenantDatabaseEndpointMarkerReconciler::class,
            ReconcileTenantDatabaseEndpointMarker::class,
        );

        return $connection;
    }

    /** @return array<string, array{string}> */
    public static function forbiddenEndpointAddresses(): array
    {
        return [
            'private address' => ['10.20.30.40'],
            'loopback address' => ['127.0.0.2'],
            'link-local address' => ['169.254.20.30'],
            'multicast address' => ['224.0.0.1'],
        ];
    }
}

final class EndpointRotationMarkerDouble implements TenantDatabaseEndpointMarkerReconciler
{
    public bool $failAfterCentral = false;

    public bool $failAfterMarker = false;

    public ?Closure $beforeCallback = null;

    public function __construct(
        public string $markerFingerprint,
        public string $markerHmac,
    ) {}

    public function reconcile(
        Shop $shop,
        ValidatedTenantConnection $candidate,
        #[\SensitiveParameter]
        string $oldFingerprint,
        #[\SensitiveParameter]
        string $oldMarkerHmac,
        Closure $afterMarkerVerified,
    ): void {
        if (hash_equals($oldFingerprint, $this->markerFingerprint)
            && hash_equals($oldMarkerHmac, $this->markerHmac)) {
            $state = TenantDatabaseEndpointMarkerState::Old;
        } elseif (hash_equals($candidate->target()->fingerprint, $this->markerFingerprint)
            && hash_equals($candidate->expectedMarkerHmac(), $this->markerHmac)) {
            $state = TenantDatabaseEndpointMarkerState::New;
        } else {
            throw new RuntimeException('Unexpected marker state.');
        }

        if ($this->beforeCallback instanceof Closure) {
            ($this->beforeCallback)();
        }

        $afterMarkerVerified($state);

        if ($this->failAfterCentral) {
            throw new RuntimeException('Simulated interruption after central commit.');
        }

        if ($state === TenantDatabaseEndpointMarkerState::Old) {
            $this->markerFingerprint = $candidate->target()->fingerprint;
            $this->markerHmac = $candidate->expectedMarkerHmac();
        }

        if ($this->failAfterMarker) {
            throw new RuntimeException('Simulated interruption after marker update.');
        }
    }
}
