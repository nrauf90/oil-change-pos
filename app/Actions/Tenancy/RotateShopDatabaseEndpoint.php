<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Enums\TenantDatabaseEndpointMarkerState;
use App\Exceptions\TenantDatabaseEndpointRotationException;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopDatabaseTargetClaim;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\TenantDatabaseEndpointMarkerReconciler;
use App\Tenancy\ValidatedTenantConnection;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class RotateShopDatabaseEndpoint
{
    public function __construct(
        private ConfigRepository $config,
        private DatabaseHostResolver $hostResolver,
        private CacheManager $cache,
    ) {}

    /**
     * @return array{
     *     pending: bool,
     *     old_target: array{
     *         driver: 'mysql',
     *         database: string,
     *         hosts: list<string>,
     *         port: int,
     *         fingerprint: string,
     *         endpoint_claim_fingerprints: list<string>
     *     },
     *     new_target: array{
     *         driver: 'mysql',
     *         database: string,
     *         hosts: list<string>,
     *         port: int,
     *         fingerprint: string,
     *         endpoints: list<array{host: string, address: string, port: int}>
     *     }
     * }
     */
    public function preview(PlatformUser $actor, Shop $shop): array
    {
        try {
            return $this->rotationPlan($actor, $shop)['preview'];
        } catch (TenantDatabaseEndpointRotationException $exception) {
            throw $exception;
        } catch (TenantDatabaseTargetConflict) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_CONFLICT',
                'The resolved database destination conflicts with a protected or assigned target.',
            );
        } catch (Throwable) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_PREVIEW_FAILED',
                'The database endpoint rotation cannot be previewed safely.',
            );
        }
    }

    /**
     * @return array{
     *     actor: PlatformUser,
     *     shop: Shop,
     *     target: NormalizedDatabaseTarget,
     *     pending_rotation: null|array{
     *         rotation_id: string,
     *         old_target_fingerprint: string,
     *         new_target_fingerprint: string
     *     },
     *     preview: array<string, mixed>
     * }
     */
    private function rotationPlan(PlatformUser $actor, Shop $shop): array
    {
        $actor = PlatformUser::query()->find($actor->getKey());

        if (! $actor instanceof PlatformUser
            || ! $actor->is_active
            || $actor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_UNAUTHORIZED',
                'Active super administrator access is required.',
            );
        }

        $shop = Shop::query()->with('databaseTargetClaims')->find($shop->getKey());

        if (! $shop instanceof Shop
            || ! in_array($shop->status, [ShopStatus::Active, ShopStatus::Suspended], true)
            || $shop->database_driver !== 'mysql'
            || $shop->database_socket !== null) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_TARGET_INELIGIBLE',
                'The shop does not have an eligible remote MySQL DNS target.',
            );
        }

        $target = $this->normalizedTarget($shop);
        $this->assertPermittedDestination($target);
        $hosts = is_array($target->effectiveHost)
            ? $target->effectiveHost
            : [$target->effectiveHost];

        if (in_array(null, $hosts, true) || $target->effectivePort === null) {
            throw new LogicException('This shop does not have an eligible MySQL DNS target.');
        }

        /** @var list<string> $hosts */
        $hosts = array_values($hosts);

        foreach ($hosts as $host) {
            if (inet_pton($host) === false && $host !== 'localhost') {
                continue;
            }

            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DNS_REQUIRED',
                'Endpoint rotation requires a registered DNS hostname.',
            );
        }

        $pendingRotation = $this->pendingRotation($shop, $target);

        if ($pendingRotation === null
            && hash_equals((string) $shop->database_target_fingerprint, $target->fingerprint)) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_NOT_REQUIRED',
                'The registered database endpoint still matches the current DNS result.',
            );
        }

        if (ShopDatabaseTargetClaim::query()
            ->whereIn('fingerprint', $target->claimFingerprints())
            ->where('shop_id', '!=', $shop->getKey())
            ->exists()) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_ASSIGNED',
                'The resolved database destination is assigned to another shop.',
            );
        }

        $claims = $shop->databaseTargetClaims
            ->pluck('fingerprint')
            ->map(static fn (mixed $fingerprint): string => (string) $fingerprint)
            ->all();

        return [
            'actor' => $actor,
            'shop' => $shop,
            'target' => $target,
            'pending_rotation' => $pendingRotation,
            'preview' => [
                'pending' => $pendingRotation !== null,
                'old_target' => [
                    'driver' => 'mysql',
                    'database' => (string) $shop->database_name,
                    'hosts' => $hosts,
                    'port' => $target->effectivePort,
                    'fingerprint' => $pendingRotation['old_target_fingerprint']
                        ?? (string) $shop->database_target_fingerprint,
                    'endpoint_claim_fingerprints' => $pendingRotation === null ? $claims : [],
                ],
                'new_target' => [
                    'driver' => 'mysql',
                    'database' => $target->database,
                    'hosts' => $hosts,
                    'port' => $target->effectivePort,
                    'fingerprint' => $target->fingerprint,
                    'endpoints' => $target->mysqlEndpoints,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function handle(
        PlatformUser $actor,
        Shop $shop,
        #[\SensitiveParameter]
        string $confirmation,
    ): array {
        try {
            return $this->withRotationLock(
                $shop,
                fn (): array => $this->handleLocked($actor, $shop, $confirmation),
            );
        } catch (TenantDatabaseEndpointRotationException $exception) {
            throw $exception;
        } catch (TenantDatabaseTargetConflict) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_CONFLICT',
                'The resolved database destination conflicts with a protected or assigned target.',
            );
        } catch (Throwable) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_FAILED',
                'The database endpoint rotation could not be started safely.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function handleLocked(
        PlatformUser $actor,
        Shop $shop,
        #[\SensitiveParameter]
        string $confirmation,
    ): array {
        $plan = $this->rotationPlan($actor, $shop);
        $freshShop = $plan['shop'];

        if (! hash_equals((string) $freshShop->slug, $confirmation)) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_CONFIRMATION_INVALID',
                'Type the exact shop slug to confirm database endpoint rotation.',
            );
        }

        $oldFingerprint = $plan['preview']['old_target']['fingerprint'];
        $oldClaimFingerprints = $plan['preview']['old_target']['endpoint_claim_fingerprints'];
        $target = $plan['target'];
        $candidate = $this->candidateConnection($freshShop, $target);
        $pendingRotation = $plan['pending_rotation'];
        $rotationId = $pendingRotation['rotation_id'] ?? (string) Str::uuid();
        $metadata = [
            'rotation_id' => $rotationId,
            'old_target_fingerprint' => $oldFingerprint,
            'new_target_fingerprint' => $target->fingerprint,
        ];

        try {
            resolve(TenantDatabaseEndpointMarkerReconciler::class)->reconcile(
                $freshShop,
                $candidate,
                $oldFingerprint,
                $freshShop->databaseAttestationHmacForFingerprint($oldFingerprint),
                function (TenantDatabaseEndpointMarkerState $markerState) use (
                    $freshShop,
                    $plan,
                    $oldFingerprint,
                    $oldClaimFingerprints,
                    $target,
                    $metadata,
                    $pendingRotation,
                ): void {
                    if ($pendingRotation === null
                        && $markerState !== TenantDatabaseEndpointMarkerState::Old) {
                        throw TenantDatabaseEndpointRotationException::safe(
                            'ROTATION_STATE_CONFLICT',
                            'The database endpoint rotation state requires operator review.',
                        );
                    }

                    DB::connection('central')->transaction(function () use (
                        $freshShop,
                        $plan,
                        $oldFingerprint,
                        $oldClaimFingerprints,
                        $target,
                        $metadata,
                        $pendingRotation,
                    ): void {
                        $lockedActor = $this->lockedAuthorizedActor((string) $plan['actor']->getKey());
                        $lockedShop = Shop::on('central')
                            ->whereKey($freshShop->getKey())
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedShop instanceof Shop) {
                            throw TenantDatabaseEndpointRotationException::safe(
                                'ROTATION_STATE_CHANGED',
                                'The registered database endpoint changed before rotation completed.',
                            );
                        }

                        if ($pendingRotation !== null) {
                            $persistedPending = $this->pendingRotation($lockedShop, $target);

                            if ($persistedPending !== $pendingRotation) {
                                throw TenantDatabaseEndpointRotationException::safe(
                                    'ROTATION_STATE_CHANGED',
                                    'The registered database endpoint changed before rotation completed.',
                                );
                            }

                            return;
                        }

                        if (! hash_equals(
                            $oldFingerprint,
                            (string) $lockedShop->database_target_fingerprint,
                        )) {
                            throw TenantDatabaseEndpointRotationException::safe(
                                'ROTATION_STATE_CHANGED',
                                'The registered database endpoint changed before rotation completed.',
                            );
                        }

                        $lockedShop->rotateDatabaseEndpoint(
                            $target,
                            $oldFingerprint,
                            $oldClaimFingerprints,
                        );
                        resolve(RecordShopLifecycleActivity::class)->handle(
                            $lockedShop,
                            ShopLifecycleEvent::DatabaseEndpointRotationStarted,
                            $lockedActor,
                            $metadata,
                        );
                    });
                },
            );

            DB::connection('central')->transaction(function () use (
                $freshShop,
                $plan,
                $target,
                $metadata,
            ): void {
                $lockedActor = $this->lockedAuthorizedActor((string) $plan['actor']->getKey());
                $lockedShop = Shop::on('central')
                    ->whereKey($freshShop->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedShop instanceof Shop
                    || ! hash_equals(
                        $target->fingerprint,
                        (string) $lockedShop->database_target_fingerprint,
                    )) {
                    throw TenantDatabaseEndpointRotationException::safe(
                        'ROTATION_STATE_CHANGED',
                        'The registered database endpoint changed before rotation completed.',
                    );
                }

                $persistedPending = $this->pendingRotation($lockedShop, $target);

                if (! is_array($persistedPending)
                    || ! hash_equals($metadata['rotation_id'], $persistedPending['rotation_id'])
                    || $persistedPending !== $metadata) {
                    throw TenantDatabaseEndpointRotationException::safe(
                        'ROTATION_STATE_CHANGED',
                        'The registered database endpoint changed before rotation completed.',
                    );
                }

                resolve(RecordShopLifecycleActivity::class)->handle(
                    $lockedShop,
                    ShopLifecycleEvent::DatabaseEndpointRotationCompleted,
                    $lockedActor,
                    $metadata,
                );
            });
        } catch (TenantDatabaseEndpointRotationException $exception) {
            throw $exception;
        } catch (TenantDatabaseTargetConflict) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_ASSIGNED',
                'The resolved database destination is assigned to another shop.',
            );
        } catch (Throwable) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_RECONCILIATION_FAILED',
                'The database endpoint rotation could not be reconciled. Retry or review its audit state.',
            );
        }

        return $plan['preview'];
    }

    /**
     * @return null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string
     * }
     */
    private function pendingRotation(Shop $shop, NormalizedDatabaseTarget $target): ?array
    {
        $activities = $shop->lifecycleActivities()
            ->whereIn('event', [
                ShopLifecycleEvent::DatabaseEndpointRotationStarted->value,
                ShopLifecycleEvent::DatabaseEndpointRotationCompleted->value,
            ])
            ->get(['event', 'metadata']);
        $started = [];
        $completed = [];

        foreach ($activities as $activity) {
            $metadata = $activity->metadata;

            if (! is_array($metadata) || ! $this->isExactRotationMetadata($metadata)) {
                throw TenantDatabaseEndpointRotationException::safe(
                    'ROTATION_STATE_CONFLICT',
                    'The database endpoint rotation state requires operator review.',
                );
            }

            $rotationId = $metadata['rotation_id'];
            $records = $activity->event === ShopLifecycleEvent::DatabaseEndpointRotationStarted
                ? $started
                : $completed;

            if (array_key_exists($rotationId, $records)) {
                throw TenantDatabaseEndpointRotationException::safe(
                    'ROTATION_STATE_CONFLICT',
                    'The database endpoint rotation state requires operator review.',
                );
            }

            if ($activity->event === ShopLifecycleEvent::DatabaseEndpointRotationStarted) {
                $started[$rotationId] = $metadata;
            } else {
                $completed[$rotationId] = $metadata;
            }
        }

        foreach ($completed as $rotationId => $metadata) {
            if (! isset($started[$rotationId]) || $started[$rotationId] !== $metadata) {
                throw TenantDatabaseEndpointRotationException::safe(
                    'ROTATION_STATE_CONFLICT',
                    'The database endpoint rotation state requires operator review.',
                );
            }
        }

        $pending = array_diff_key($started, $completed);

        if (count($pending) > 1) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_STATE_CONFLICT',
                'The database endpoint rotation state requires operator review.',
            );
        }

        $metadata = array_values($pending)[0] ?? null;

        if ($metadata === null) {
            return null;
        }

        if (! hash_equals($metadata['new_target_fingerprint'], $target->fingerprint)
            || ! hash_equals(
                $metadata['new_target_fingerprint'],
                (string) $shop->database_target_fingerprint,
            )) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_STATE_CONFLICT',
                'The database endpoint rotation state requires operator review.',
            );
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function isExactRotationMetadata(array $metadata): bool
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);

        if ($keys !== [
            'new_target_fingerprint',
            'old_target_fingerprint',
            'rotation_id',
        ]) {
            return false;
        }

        return is_string($metadata['rotation_id'])
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $metadata['rotation_id'],
            ) === 1
            && is_string($metadata['old_target_fingerprint'])
            && preg_match('/\A[a-f0-9]{64}\z/', $metadata['old_target_fingerprint']) === 1
            && is_string($metadata['new_target_fingerprint'])
            && preg_match('/\A[a-f0-9]{64}\z/', $metadata['new_target_fingerprint']) === 1
            && ! hash_equals(
                $metadata['old_target_fingerprint'],
                $metadata['new_target_fingerprint'],
            );
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    private function withRotationLock(Shop $shop, Closure $operation): mixed
    {
        if ($this->config->get('cache.stores.database.driver') !== 'database'
            || $this->config->get('cache.stores.database.connection') !== 'central'
            || $this->config->get('cache.stores.database.lock_connection') !== 'central') {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_LOCK_MISCONFIGURED',
                'Database endpoint rotation requires the shared central lock store.',
            );
        }

        $configuredSeconds = $this->config->get('database.tenant_provisioning_lock_seconds', 900);
        $seconds = is_int($configuredSeconds)
            ? $configuredSeconds
            : (is_string($configuredSeconds) && ctype_digit($configuredSeconds)
                ? (int) $configuredSeconds
                : 0);

        if ($seconds < 1) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_LOCK_MISCONFIGURED',
                'Database endpoint rotation requires a positive lock duration.',
            );
        }

        $lock = $this->cache->store('database')->lock(
            'tenant-endpoint-rotation:'.$shop->getKey(),
            $seconds,
        );

        if (! $lock instanceof DatabaseLock) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_LOCK_MISCONFIGURED',
                'Database endpoint rotation requires a shared central database lock.',
            );
        }

        try {
            if (! $lock->get()) {
                throw TenantDatabaseEndpointRotationException::safe(
                    'ROTATION_BUSY',
                    'Another database endpoint rotation is already running for this shop.',
                );
            }
        } catch (TenantDatabaseEndpointRotationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_LOCK_UNAVAILABLE',
                'The database endpoint rotation lock is unavailable. Retry later.',
            );
        }

        try {
            return $operation();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    private function lockedAuthorizedActor(string $actorId): PlatformUser
    {
        $actor = PlatformUser::on('central')
            ->whereKey($actorId)
            ->lockForUpdate()
            ->first();

        if (! $actor instanceof PlatformUser
            || ! $actor->is_active
            || $actor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_UNAUTHORIZED',
                'Active super administrator access is required.',
            );
        }

        return $actor;
    }

    private function candidateConnection(
        Shop $shop,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): ValidatedTenantConnection {
        $configuration = [
            'driver' => $target->driver,
            'database' => $target->database,
        ];

        foreach ([
            'host' => $target->host,
            'port' => $target->port,
            'unix_socket' => $target->socket,
            'username' => $shop->database_username,
            'password' => $shop->database_password,
        ] as $key => $value) {
            if ($value !== null) {
                $configuration[$key] = $value;
            }
        }

        return new ValidatedTenantConnection(
            $configuration,
            $target,
            $shop->databaseAttestationHmacForFingerprint($target->fingerprint),
        );
    }

    private function normalizedTarget(Shop $shop): NormalizedDatabaseTarget
    {
        $tenantConfiguration = $this->config->get('database.tenant_connection_template');
        $centralConfiguration = $this->config->get('database.connections.central');
        $sqliteRoot = $this->config->get('database.tenant_sqlite_root');

        if (! is_array($tenantConfiguration)
            || ! is_array($centralConfiguration)
            || ! is_string($sqliteRoot)
            || $sqliteRoot === '') {
            throw new LogicException('Tenant database target configuration is incomplete.');
        }

        return NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: (string) $shop->database_driver,
                database: (string) $shop->database_name,
                host: $shop->database_host,
                port: $shop->database_port === null ? null : (int) $shop->database_port,
                socket: $shop->database_socket,
            ),
            defaults: DatabaseTargetConfiguration::fromLaravelConfiguration($tenantConfiguration),
            central: DatabaseTargetConfiguration::fromLaravelConfiguration($centralConfiguration),
            sqliteRoot: $sqliteRoot,
            hostResolver: $this->hostResolver,
        );
    }

    private function assertPermittedDestination(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        foreach ($target->mysqlEndpoints as $endpoint) {
            if ($this->isPublicUnicastAddress($endpoint['address'])) {
                continue;
            }

            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_FORBIDDEN',
                'The resolved database destination is not permitted.',
            );
        }
    }

    private function isPublicUnicastAddress(#[\SensitiveParameter] string $address): bool
    {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        $packedAddress = inet_pton($address);

        if (! is_string($packedAddress)) {
            return false;
        }

        return ! ((strlen($packedAddress) === 4 && ord($packedAddress[0]) >= 224)
            || (strlen($packedAddress) === 16 && ord($packedAddress[0]) === 255));
    }
}
