<?php

namespace Tests\Feature\Platform;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
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
use App\Tenancy\TenantDatabaseEndpointMarkerObservation;
use App\Tenancy\TenantDatabaseEndpointMarkerReconciler;
use App\Tenancy\ValidatedTenantConnection;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
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

    public function test_preview_rejects_a_nat64_dns_answer_without_mutation(): void
    {
        $address = '64:ff9b::7f00:1';
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Forbidden Endpoint Shop',
            slug: 'forbidden-endpoint-shop-'.substr(hash('sha256', $address), 0, 12),
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

    public function test_preview_rejects_all_dns_answers_when_one_destination_is_forbidden(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Mixed Endpoint Shop',
            slug: 'mixed-endpoint-shop',
            databaseDriver: 'mysql',
            databaseName: 'mixed_endpoint_shop',
            databaseHost: 'mixed-endpoint.example.test',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->resolveDatabaseHostToMany(['8.8.8.8', '100.64.0.1']);

        try {
            app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
            $this->fail('A DNS target with a forbidden answer was offered for rotation.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_DESTINATION_FORBIDDEN', $exception->errorCode);
            $this->assertSame(
                'The resolved database destination is not permitted.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('100.64.0.1', $exception->getMessage());
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

    public function test_preview_allows_an_ordinary_public_unicast_endpoint(): void
    {
        $address = '8.8.8.8';
        $this->resolveDatabaseHostTo('9.9.9.9');
        $shop = Shop::registerForProvisioning(
            name: 'Public Endpoint Shop',
            slug: 'public-endpoint-shop-'.substr(hash('sha256', $address), 0, 12),
            databaseDriver: 'mysql',
            databaseName: 'public_endpoint_shop',
            databaseHost: 'public-endpoint.example.test',
        );
        $shop->markActive();
        $this->resolveDatabaseHostTo($address);

        $preview = app(RotateShopDatabaseEndpoint::class)->preview(
            PlatformUser::factory()->create(),
            $shop,
        );

        $this->assertSame($address, $preview['new_target']['endpoints'][0]['address']);
    }

    public function test_preview_allows_a_public_candidate_when_the_central_mysql_host_is_private(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Private Central Host Shop',
            slug: 'private-central-host-shop',
            databaseDriver: 'mysql',
            databaseName: 'private_central_host_shop',
            databaseHost: 'candidate-database.example.test',
        );
        $shop->markActive();
        config()->set('database.connections.central', [
            'driver' => 'mysql',
            'database' => 'central',
            'host' => 'central-database.internal.test',
            'port' => 3306,
        ]);
        $this->resolveDatabaseHostsTo([
            'candidate-database.example.test' => ['8.8.8.8'],
            'central-database.internal.test' => ['127.0.0.1'],
        ]);

        $preview = app(RotateShopDatabaseEndpoint::class)->preview(
            PlatformUser::factory()->create(),
            $shop,
        );

        $this->assertSame('8.8.8.8', $preview['new_target']['endpoints'][0]['address']);
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
                array $eligibleSourceMarkerHmacs,
                Closure $afterMarkerVerified,
            ): void {
                $this->observedShopId = (string) $shop->getKey();
                $this->observedOldFingerprint = $this->markerFingerprint;
                $state = isset($eligibleSourceMarkerHmacs[$this->markerFingerprint])
                    && $this->markerHmac === $eligibleSourceMarkerHmacs[$this->markerFingerprint]
                    ? TenantDatabaseEndpointMarkerState::Old
                    : TenantDatabaseEndpointMarkerState::New;

                $afterMarkerVerified(new TenantDatabaseEndpointMarkerObservation(
                    $state,
                    $this->markerFingerprint,
                ));

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
            $this->rotationPreviewToken($actor, $shop),
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
        $actor = PlatformUser::factory()->create();
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                'Confirmation-Shop',
                $previewToken,
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

    public function test_dns_change_after_preview_is_rejected_before_candidate_connection_or_mutation(): void
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Preview Bound Shop',
            slug: 'preview-bound-shop',
            databaseDriver: 'mysql',
            databaseName: 'preview_bound_shop',
            databaseHost: 'preview-bound.example.test',
            databaseUsername: 'preview-bound-user',
            databasePassword: 'preview-bound-password',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->bindProductionMarkerReconciler(
            (string) $shop->getKey(),
            $oldFingerprint,
            $shop->databaseAttestationHmac(),
        );
        $runner = app(TenantDatabaseEndpointConnectionRunner::class);
        $this->assertInstanceOf(EndpointRotationConnectionRunnerDouble::class, $runner);
        $this->resolveDatabaseHostTo('8.8.8.8');

        $preview = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);

        $this->assertArrayHasKey('preview_token', $preview);
        $this->assertIsString($preview['preview_token']);
        $this->assertNotSame('', $preview['preview_token']);
        $previewToken = $preview['preview_token'];
        $this->resolveDatabaseHostTo('9.9.9.9');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewToken,
            );
            $this->fail('A database endpoint that changed after preview was opened.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_STALE', $exception->errorCode);
            $this->assertSame(
                'The database endpoint changed after preview. Reopen the rotation and confirm the new target.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('8.8.8.8', $exception->getMessage());
            $this->assertStringNotContainsString('9.9.9.9', $exception->getMessage());
            $this->assertStringNotContainsString('preview-bound', $exception->getMessage());
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
        }

        $persistedShop = $shop->fresh();

        $this->assertSame(0, $runner->calls);
        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
    }

    public function test_tampered_preview_token_is_rejected_before_candidate_connection(): void
    {
        [$shop, $actor, $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'tampered-preview-token',
        );
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);
        $lastCharacter = substr($previewToken, -1);
        $tamperedToken = substr($previewToken, 0, -1).($lastCharacter === 'a' ? 'b' : 'a');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $tamperedToken,
            );
            $this->fail('A tampered endpoint preview token was accepted.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_INVALID', $exception->errorCode);
            $this->assertSame(
                'The database endpoint preview is invalid. Reopen the rotation and try again.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
            $this->assertStringNotContainsString('tampered-preview-token', $exception->getMessage());
        }

        $this->assertRotationStateUnchanged($shop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_expired_preview_token_is_rejected_before_candidate_connection(): void
    {
        $this->travelTo('2026-09-03 10:00:00');
        [$shop, $actor, $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'expired-preview-token',
        );
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);
        $this->travel(301)->seconds();

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewToken,
            );
            $this->fail('An expired endpoint preview token was accepted.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_EXPIRED', $exception->errorCode);
            $this->assertSame(
                'The database endpoint preview expired. Reopen the rotation and confirm again.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
        }

        $this->assertRotationStateUnchanged($shop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_preview_token_is_bound_to_the_actor(): void
    {
        [$shop, $actor, $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'session-bound-preview-token',
        );
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);
        $otherActor = PlatformUser::factory()->create();

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $otherActor,
                $shop,
                $shop->slug,
                $previewToken,
            );
            $this->fail('An endpoint preview token crossed its actor and session boundary.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_INVALID', $exception->errorCode);
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
            $this->assertStringNotContainsString((string) $actor->getKey(), $exception->getMessage());
            $this->assertStringNotContainsString((string) $otherActor->getKey(), $exception->getMessage());
        }

        $this->assertRotationStateUnchanged($shop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_preview_token_is_bound_to_the_session(): void
    {
        [$shop, $actor, $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'session-only-bound-preview-token',
        );
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);
        session()->regenerate(true);

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewToken,
            );
            $this->fail('An endpoint preview token crossed its session boundary.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_INVALID', $exception->errorCode);
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
            $this->assertStringNotContainsString((string) $actor->getKey(), $exception->getMessage());
        }

        $this->assertRotationStateUnchanged($shop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_preview_token_is_bound_to_the_shop(): void
    {
        [$sourceShop, $actor] = $this->previewBoundScenario('source-preview-token');
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $sourceShop);
        [$otherShop, , $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'other-preview-token',
        );
        $this->resolveDatabaseHostTo('9.9.9.9');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $otherShop,
                $otherShop->slug,
                $previewToken,
            );
            $this->fail('An endpoint preview token was accepted for another shop.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_INVALID', $exception->errorCode);
            $this->assertStringNotContainsString($previewToken, $exception->getMessage());
            $this->assertStringNotContainsString((string) $sourceShop->getKey(), $exception->getMessage());
            $this->assertStringNotContainsString((string) $otherShop->getKey(), $exception->getMessage());
        }

        $this->assertRotationStateUnchanged($otherShop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_stale_preview_token_cannot_be_replayed_after_dns_returns_to_the_previewed_target(): void
    {
        [$shop, $actor, $runner, $oldFingerprint, $oldClaims] = $this->previewBoundScenario(
            'single-use-preview-token',
        );
        $this->resolveDatabaseHostTo('8.8.8.8');
        $previewToken = $this->rotationPreviewToken($actor, $shop);
        $this->resolveDatabaseHostTo('9.9.9.9');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewToken,
            );
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_STALE', $exception->errorCode);
        }

        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewToken,
            );
            $this->fail('A consumed endpoint preview token was replayed.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_PREVIEW_INVALID', $exception->errorCode);
            $this->assertSame(
                'The database endpoint preview is invalid. Reopen the rotation and try again.',
                $exception->getMessage(),
            );
        }

        $this->assertRotationStateUnchanged($shop, $runner, $oldFingerprint, $oldClaims);
    }

    public function test_dns_drift_supersedes_a_pending_rotation_when_the_marker_is_still_old_and_retries_from_that_marker(): void
    {
        [$shop, $actor, $marker, $fingerprintA] = $this->rotationScenario(
            'old-marker-drift-recovery',
        );
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $this->rotationPreviewToken($actor, $shop),
            );
            $this->fail('The first simulated interruption did not fail.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $fingerprintB = (string) $shop->fresh()->database_target_fingerprint;
        $rotationOne = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationStarted)
            ->sole();
        $marker->failAfterCentral = false;
        $this->resolveDatabaseHostTo('9.9.9.9');
        $previewC = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
        $fingerprintC = $previewC['new_target']['fingerprint'];
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewC['preview_token'],
            );
            $this->fail('The superseding central transition did not interrupt.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('9.9.9.9', $exception->getMessage());
        }

        $rotationTwo = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationStarted)
            ->where('metadata->rotation_id', '!=', $rotationOne->metadata['rotation_id'])
            ->sole();
        $superseded = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationSuperseded)
            ->sole();

        $this->assertSame($fingerprintC, $shop->fresh()->database_target_fingerprint);
        $this->assertSame($fingerprintA, $marker->markerFingerprint);
        $this->assertSame($rotationOne->metadata, $superseded->metadata);
        $this->assertSame($fingerprintB, $rotationTwo->metadata['old_target_fingerprint']);
        $this->assertSame($fingerprintC, $rotationTwo->metadata['new_target_fingerprint']);
        $this->assertSame($fingerprintA, $rotationTwo->metadata['marker_source_fingerprint']);
        $this->assertSame(
            $rotationOne->metadata['rotation_id'],
            $rotationTwo->metadata['predecessor_rotation_id'],
        );
        $this->assertSame($actor->getKey(), $superseded->platform_user_id);
        $this->assertSame($actor->getKey(), $rotationTwo->platform_user_id);

        $marker->failAfterCentral = false;
        $marker->failAfterMarker = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $this->rotationPreviewToken($actor, $shop),
            );
            $this->fail('The retried marker transition did not interrupt.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $this->assertSame($fingerprintC, $marker->markerFingerprint);
        $this->assertSame(0, $this->rotationEventCount(
            $shop,
            ShopLifecycleEvent::DatabaseEndpointRotationCompleted->value,
        ));

        $marker->failAfterMarker = false;
        app(RotateShopDatabaseEndpoint::class)->handle(
            $actor,
            $shop,
            $shop->slug,
            $this->rotationPreviewToken($actor, $shop),
        );

        $completion = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationCompleted)
            ->sole();

        $this->assertSame($rotationTwo->metadata, $completion->metadata);
        $this->assertSame(2, $this->rotationEventCount(
            $shop,
            ShopLifecycleEvent::DatabaseEndpointRotationStarted->value,
        ));
        $this->assertSame(1, $this->rotationEventCount(
            $shop,
            ShopLifecycleEvent::DatabaseEndpointRotationSuperseded->value,
        ));
    }

    public function test_dns_drift_completes_a_pending_rotation_when_the_marker_is_new_then_starts_from_that_marker(): void
    {
        [$shop, $actor, $marker, $fingerprintA] = $this->rotationScenario(
            'new-marker-drift-recovery',
        );
        $marker->failAfterMarker = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $this->rotationPreviewToken($actor, $shop),
            );
            $this->fail('The first marker interruption did not fail.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $fingerprintB = (string) $shop->fresh()->database_target_fingerprint;
        $rotationOne = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationStarted)
            ->sole();

        $this->assertNotSame($fingerprintA, $fingerprintB);
        $this->assertSame($fingerprintB, $marker->markerFingerprint);

        $marker->failAfterMarker = false;
        $this->resolveDatabaseHostTo('9.9.9.9');
        $previewC = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
        $fingerprintC = $previewC['new_target']['fingerprint'];
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $previewC['preview_token'],
            );
            $this->fail('The second central transition did not interrupt.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_RECONCILIATION_FAILED', $exception->errorCode);
        }

        $rotationTwo = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationStarted)
            ->where('metadata->rotation_id', '!=', $rotationOne->metadata['rotation_id'])
            ->sole();
        $firstCompletion = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationCompleted)
            ->where('metadata->rotation_id', $rotationOne->metadata['rotation_id'])
            ->sole();

        $this->assertSame($rotationOne->metadata, $firstCompletion->metadata);
        $this->assertSame($fingerprintC, $shop->fresh()->database_target_fingerprint);
        $this->assertSame($fingerprintB, $marker->markerFingerprint);
        $this->assertSame($fingerprintB, $rotationTwo->metadata['old_target_fingerprint']);
        $this->assertSame($fingerprintC, $rotationTwo->metadata['new_target_fingerprint']);
        $this->assertSame($fingerprintB, $rotationTwo->metadata['marker_source_fingerprint']);
        $this->assertSame(
            $rotationOne->metadata['rotation_id'],
            $rotationTwo->metadata['predecessor_rotation_id'],
        );

        $marker->failAfterCentral = false;
        app(RotateShopDatabaseEndpoint::class)->handle(
            $actor,
            $shop,
            $shop->slug,
            $this->rotationPreviewToken($actor, $shop),
        );

        $secondCompletion = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationCompleted)
            ->where('metadata->rotation_id', $rotationTwo->metadata['rotation_id'])
            ->sole();

        $this->assertSame($rotationTwo->metadata, $secondCompletion->metadata);
        $this->assertSame($fingerprintC, $marker->markerFingerprint);
        $this->assertSame(0, $this->rotationEventCount(
            $shop,
            ShopLifecycleEvent::DatabaseEndpointRotationSuperseded->value,
        ));
    }

    public function test_retry_recovers_old_marker_after_central_rotation_without_duplicate_start(): void
    {
        [$shop, $actor, $marker, $oldFingerprint] = $this->rotationScenario(
            'old-marker-recovery',
        );
        $marker->failAfterCentral = true;

        try {
            $this->confirmedRotation($actor, $shop);
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
        $result = $this->confirmedRotation($actor, $shop);

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
            $this->confirmedRotation($actor, $shop);
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
        $result = $this->confirmedRotation($actor, $shop);

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
            $this->confirmedRotation($actor, $shop);
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
            new EndpointRotationConnectionRunnerDouble($connection),
        );
        app()->bind(
            TenantDatabaseEndpointMarkerReconciler::class,
            ReconcileTenantDatabaseEndpointMarker::class,
        );
        $actor = PlatformUser::factory()->create();
        $this->resolveDatabaseHostTo('8.8.8.8');

        $result = app(RotateShopDatabaseEndpoint::class)->handle(
            $actor,
            $shop,
            $shop->slug,
            $this->rotationPreviewToken($actor, $shop),
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
            ->mountAction('rotateDatabaseEndpoint')
            ->assertSchemaStateSet(function (array $state) use ($oldFingerprint): array {
                $this->assertIsString($state['preview_summary'] ?? null);
                $this->assertStringContainsString($oldFingerprint, $state['preview_summary']);
                $this->assertStringContainsString('8.8.8.8', $state['preview_summary']);
                $this->assertIsString($state['preview_token'] ?? null);
                $this->assertNotSame('', $state['preview_token']);

                return [];
            })
            ->fillForm([
                'confirmation' => $shop->slug,
            ])
            ->callMountedAction()
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
        $actor = PlatformUser::factory()->create();
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $this->rotationPreviewToken($actor, $shop),
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
        $actor = PlatformUser::factory()->create();
        $this->resolveDatabaseHostTo('8.8.8.8');

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $this->rotationPreviewToken($actor, $shop),
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
            $this->confirmedRotation($actor, $shop);
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
            $this->confirmedRotation($actor, $shop);
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

    public function test_orphaned_superseded_rotation_audit_is_rejected(): void
    {
        [$shop, $actor, $marker] = $this->rotationScenario('orphaned-supersession');
        $marker->failAfterCentral = true;

        try {
            $this->confirmedRotation($actor, $shop);
        } catch (TenantDatabaseEndpointRotationException) {
        }

        $started = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationStarted)
            ->sole();
        resolve(RecordShopLifecycleActivity::class)->handle(
            $shop,
            ShopLifecycleEvent::DatabaseEndpointRotationSuperseded,
            $actor,
            $started->metadata,
        );

        try {
            app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
            $this->fail('An orphaned supersession audit was accepted.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_STATE_CONFLICT', $exception->errorCode);
        }
    }

    public function test_supersession_terminal_must_precede_its_successor_start(): void
    {
        [$shop, $actor, $marker] = $this->rotationScenario('late-supersession-terminal');
        $marker->failAfterCentral = true;

        try {
            $this->confirmedRotation($actor, $shop);
        } catch (TenantDatabaseEndpointRotationException) {
        }

        $marker->failAfterCentral = false;
        $this->resolveDatabaseHostTo('9.9.9.9');
        $preview = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
        $marker->failAfterCentral = true;

        try {
            app(RotateShopDatabaseEndpoint::class)->handle(
                $actor,
                $shop,
                $shop->slug,
                $preview['preview_token'],
            );
        } catch (TenantDatabaseEndpointRotationException) {
        }

        $superseded = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::DatabaseEndpointRotationSuperseded)
            ->sole();
        DB::connection('central')
            ->table('shop_lifecycle_activities')
            ->where('id', $superseded->getKey())
            ->update(['id' => 'ffffffff-ffff-8fff-bfff-ffffffffffff']);

        try {
            app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);
            $this->fail('A successor recorded before its predecessor terminal was accepted.');
        } catch (TenantDatabaseEndpointRotationException $exception) {
            $this->assertSame('ROTATION_STATE_CONFLICT', $exception->errorCode);
        }
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
            $this->confirmedRotation($actor, $shop);
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
        $this->resolveDatabaseHostToMany([$address]);
    }

    /** @param list<string> $addresses */
    private function resolveDatabaseHostToMany(array $addresses): void
    {
        app()->instance(DatabaseHostResolver::class, new class($addresses) implements DatabaseHostResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            public function resolve(string $host): array
            {
                return $this->addresses;
            }
        });
    }

    /** @param array<string, list<string>> $addressesByHost */
    private function resolveDatabaseHostsTo(array $addressesByHost): void
    {
        app()->instance(DatabaseHostResolver::class, new class($addressesByHost) implements DatabaseHostResolver
        {
            /** @param array<string, list<string>> $addressesByHost */
            public function __construct(private readonly array $addressesByHost) {}

            public function resolve(string $host): array
            {
                return $this->addressesByHost[$host] ?? [];
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

    /**
     * @return array{Shop, PlatformUser, EndpointRotationConnectionRunnerDouble, string, list<string>}
     */
    private function previewBoundScenario(string $slug): array
    {
        $this->resolveDatabaseHostTo('1.1.1.1');
        $shop = Shop::registerForProvisioning(
            name: 'Preview Token Shop',
            slug: $slug,
            databaseDriver: 'mysql',
            databaseName: str_replace('-', '_', $slug),
            databaseHost: $slug.'.example.test',
            databaseUsername: $slug.'-user',
            databasePassword: $slug.'-password',
        );
        $shop->markActive();
        $actor = PlatformUser::factory()->create();
        $oldFingerprint = (string) $shop->database_target_fingerprint;
        $oldClaims = $shop->databaseTargetClaims()->pluck('fingerprint')->all();
        $this->bindProductionMarkerReconciler(
            (string) $shop->getKey(),
            $oldFingerprint,
            $shop->databaseAttestationHmac(),
        );
        $runner = app(TenantDatabaseEndpointConnectionRunner::class);
        $this->assertInstanceOf(EndpointRotationConnectionRunnerDouble::class, $runner);

        return [$shop, $actor, $runner, $oldFingerprint, $oldClaims];
    }

    private function rotationPreviewToken(PlatformUser $actor, Shop $shop): string
    {
        $preview = app(RotateShopDatabaseEndpoint::class)->preview($actor, $shop);

        $this->assertArrayHasKey('preview_token', $preview);
        $this->assertIsString($preview['preview_token']);
        $this->assertNotSame('', $preview['preview_token']);

        return $preview['preview_token'];
    }

    /** @return array<string, mixed> */
    private function confirmedRotation(PlatformUser $actor, Shop $shop): array
    {
        return app(RotateShopDatabaseEndpoint::class)->handle(
            $actor,
            $shop,
            $shop->slug,
            $this->rotationPreviewToken($actor, $shop),
        );
    }

    /** @param list<string> $oldClaims */
    private function assertRotationStateUnchanged(
        Shop $shop,
        EndpointRotationConnectionRunnerDouble $runner,
        string $oldFingerprint,
        array $oldClaims,
    ): void {
        $persistedShop = $shop->fresh();

        $this->assertSame(0, $runner->calls);
        $this->assertSame($oldFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($oldClaims, $persistedShop->databaseTargetClaims()->pluck('fingerprint')->all());
        $this->assertSame(0, $persistedShop->lifecycleActivities()->count());
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
            new EndpointRotationConnectionRunnerDouble($connection),
        );
        app()->bind(
            TenantDatabaseEndpointMarkerReconciler::class,
            ReconcileTenantDatabaseEndpointMarker::class,
        );

        return $connection;
    }
}

final class EndpointRotationConnectionRunnerDouble implements TenantDatabaseEndpointConnectionRunner
{
    public int $calls = 0;

    public function __construct(private readonly Connection $connection) {}

    public function run(
        ValidatedTenantConnection $candidate,
        Closure $operation,
    ): mixed {
        $this->calls++;

        return $this->connection->transaction(
            fn (): mixed => $operation($this->connection),
        );
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
        array $eligibleSourceMarkerHmacs,
        Closure $afterMarkerVerified,
    ): void {
        $sourceMarkerHmac = $eligibleSourceMarkerHmacs[$this->markerFingerprint] ?? null;

        if (is_string($sourceMarkerHmac)
            && hash_equals($sourceMarkerHmac, $this->markerHmac)) {
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

        $afterMarkerVerified(new TenantDatabaseEndpointMarkerObservation(
            $state,
            $this->markerFingerprint,
        ));

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
