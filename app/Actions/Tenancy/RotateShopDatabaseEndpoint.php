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
use App\Tenancy\DatabaseEndpointRotationPreviewTokens;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\PublicIpAddressClassifier;
use App\Tenancy\TenantDatabaseEndpointMarkerObservation;
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
        private PublicIpAddressClassifier $addressClassifier,
        private DatabaseEndpointRotationPreviewTokens $previewTokens,
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
     *     },
     *     preview_token: string
     * }
     */
    public function preview(PlatformUser $actor, Shop $shop): array
    {
        try {
            $plan = $this->rotationPlan($actor, $shop);
            $prepared = $this->previewTokens->issue(
                actor: $plan['actor'],
                shop: $plan['shop'],
                currentTargetFingerprint: (string) $plan['shop']->database_target_fingerprint,
                currentClaimFingerprints: $plan['current_claim_fingerprints'],
                pendingRotation: $plan['pending_rotation'],
                candidate: $plan['target'],
            );

            return [
                ...$plan['preview'],
                'preview_token' => $prepared->token,
            ];
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
     *     current_claim_fingerprints: list<string>,
     *     pending_rotation: null|array{
     *         rotation_id: string,
     *         old_target_fingerprint: string,
     *         new_target_fingerprint: string,
     *         marker_source_fingerprint?: string,
     *         predecessor_rotation_id?: string
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

        $pendingRotation = $this->pendingRotation($shop);

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
            'current_claim_fingerprints' => $claims,
            'pending_rotation' => $pendingRotation,
            'preview' => [
                'pending' => $pendingRotation !== null,
                'old_target' => [
                    'driver' => 'mysql',
                    'database' => (string) $shop->database_name,
                    'hosts' => $hosts,
                    'port' => $target->effectivePort,
                    'fingerprint' => (string) $shop->database_target_fingerprint,
                    'endpoint_claim_fingerprints' => $claims,
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
        #[\SensitiveParameter]
        string $previewToken,
    ): array {
        try {
            return $this->withRotationLock(
                $shop,
                fn (): array => $this->handleLocked(
                    $actor,
                    $shop,
                    $confirmation,
                    $previewToken,
                ),
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
        #[\SensitiveParameter]
        string $previewToken,
    ): array {
        $plan = $this->rotationPlan($actor, $shop);
        $freshShop = $plan['shop'];

        if (! hash_equals((string) $freshShop->slug, $confirmation)) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_CONFIRMATION_INVALID',
                'Type the exact shop slug to confirm database endpoint rotation.',
            );
        }

        $this->previewTokens->consume(
            actor: $plan['actor'],
            shop: $freshShop,
            token: $previewToken,
            currentTargetFingerprint: (string) $freshShop->database_target_fingerprint,
            currentClaimFingerprints: $plan['current_claim_fingerprints'],
            pendingRotation: $plan['pending_rotation'],
            candidate: $plan['target'],
        );

        $currentFingerprint = (string) $freshShop->database_target_fingerprint;
        $currentClaimFingerprints = $plan['current_claim_fingerprints'];
        $target = $plan['target'];
        $pendingRotation = $plan['pending_rotation'];
        $eligibleSourceMarkerHmacs = $this->eligibleSourceMarkerHmacs(
            $freshShop,
            $pendingRotation,
        );
        $candidate = $this->candidateConnection($freshShop, $target);
        $activeRotationMetadata = null;

        try {
            resolve(TenantDatabaseEndpointMarkerReconciler::class)->reconcile(
                $freshShop,
                $candidate,
                $eligibleSourceMarkerHmacs,
                function (TenantDatabaseEndpointMarkerObservation $observation) use (
                    &$activeRotationMetadata,
                    $freshShop,
                    $plan,
                    $currentFingerprint,
                    $currentClaimFingerprints,
                    $target,
                    $pendingRotation,
                ): void {
                    $activeRotationMetadata = $this->prepareCentralTransition(
                        actorId: (string) $plan['actor']->getKey(),
                        shopId: (string) $freshShop->getKey(),
                        currentFingerprint: $currentFingerprint,
                        currentClaimFingerprints: $currentClaimFingerprints,
                        target: $target,
                        pendingRotation: $pendingRotation,
                        observation: $observation,
                    );
                },
            );

            if (! is_array($activeRotationMetadata)) {
                throw $this->rotationStateConflict();
            }

            $this->completeRotation(
                actorId: (string) $plan['actor']->getKey(),
                shopId: (string) $freshShop->getKey(),
                targetFingerprint: $target->fingerprint,
                metadata: $activeRotationMetadata,
            );
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
     * @param  list<string>  $currentClaimFingerprints
     * @param  null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }  $pendingRotation
     * @return array<string, string>
     */
    private function prepareCentralTransition(
        string $actorId,
        string $shopId,
        string $currentFingerprint,
        array $currentClaimFingerprints,
        NormalizedDatabaseTarget $target,
        ?array $pendingRotation,
        TenantDatabaseEndpointMarkerObservation $observation,
    ): array {
        return DB::connection('central')->transaction(function () use (
            $actorId,
            $shopId,
            $currentFingerprint,
            $currentClaimFingerprints,
            $target,
            $pendingRotation,
            $observation,
        ): array {
            $lockedActor = $this->lockedAuthorizedActor($actorId);
            $lockedShop = Shop::on('central')
                ->whereKey($shopId)
                ->lockForUpdate()
                ->first();

            if (! $lockedShop instanceof Shop
                || ! hash_equals(
                    $currentFingerprint,
                    (string) $lockedShop->database_target_fingerprint,
                )
                || $this->pendingRotation($lockedShop) !== $pendingRotation) {
                throw TenantDatabaseEndpointRotationException::safe(
                    'ROTATION_STATE_CHANGED',
                    'The registered database endpoint changed before rotation completed.',
                );
            }

            if ($pendingRotation === null) {
                if ($observation->state !== TenantDatabaseEndpointMarkerState::Old
                    || ! hash_equals($currentFingerprint, $observation->fingerprint)) {
                    throw $this->rotationStateConflict();
                }

                $metadata = $this->rotationMetadata(
                    oldFingerprint: $currentFingerprint,
                    newFingerprint: $target->fingerprint,
                    markerSourceFingerprint: $observation->fingerprint,
                );
                $lockedShop->rotateDatabaseEndpoint(
                    $target,
                    $currentFingerprint,
                    $currentClaimFingerprints,
                );
                resolve(RecordShopLifecycleActivity::class)->handle(
                    $lockedShop,
                    ShopLifecycleEvent::DatabaseEndpointRotationStarted,
                    $lockedActor,
                    $metadata,
                );

                return $metadata;
            }

            $markerSourceFingerprint = $this->markerSourceFingerprint($pendingRotation);

            if (! in_array(
                $observation->fingerprint,
                [$markerSourceFingerprint, $pendingRotation['new_target_fingerprint']],
                true,
            )) {
                throw $this->rotationStateConflict();
            }

            if (hash_equals($pendingRotation['new_target_fingerprint'], $target->fingerprint)) {
                return $pendingRotation;
            }

            $terminalEvent = hash_equals(
                $pendingRotation['new_target_fingerprint'],
                $observation->fingerprint,
            )
                ? ShopLifecycleEvent::DatabaseEndpointRotationCompleted
                : ShopLifecycleEvent::DatabaseEndpointRotationSuperseded;
            resolve(RecordShopLifecycleActivity::class)->handle(
                $lockedShop,
                $terminalEvent,
                $lockedActor,
                $pendingRotation,
            );
            $metadata = $this->rotationMetadata(
                oldFingerprint: $currentFingerprint,
                newFingerprint: $target->fingerprint,
                markerSourceFingerprint: $observation->fingerprint,
                predecessorRotationId: $pendingRotation['rotation_id'],
            );
            $lockedShop->rotateDatabaseEndpoint(
                $target,
                $currentFingerprint,
                $currentClaimFingerprints,
            );
            resolve(RecordShopLifecycleActivity::class)->handle(
                $lockedShop,
                ShopLifecycleEvent::DatabaseEndpointRotationStarted,
                $lockedActor,
                $metadata,
            );

            return $metadata;
        });
    }

    /**
     * @param array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * } $metadata
     */
    private function completeRotation(
        string $actorId,
        string $shopId,
        string $targetFingerprint,
        array $metadata,
    ): void {
        DB::connection('central')->transaction(function () use (
            $actorId,
            $shopId,
            $targetFingerprint,
            $metadata,
        ): void {
            $lockedActor = $this->lockedAuthorizedActor($actorId);
            $lockedShop = Shop::on('central')
                ->whereKey($shopId)
                ->lockForUpdate()
                ->first();

            if (! $lockedShop instanceof Shop
                || ! hash_equals(
                    $targetFingerprint,
                    (string) $lockedShop->database_target_fingerprint,
                )
                || $this->pendingRotation($lockedShop) !== $metadata) {
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
    }

    /**
     * @param  null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }  $pendingRotation
     * @return array<string, string>
     */
    private function eligibleSourceMarkerHmacs(Shop $shop, ?array $pendingRotation): array
    {
        $fingerprints = $pendingRotation === null
            ? [(string) $shop->database_target_fingerprint]
            : [
                $this->markerSourceFingerprint($pendingRotation),
                $pendingRotation['new_target_fingerprint'],
            ];
        $markerHmacs = [];

        foreach (array_unique($fingerprints) as $fingerprint) {
            $markerHmacs[$fingerprint] = $shop->databaseAttestationHmacForFingerprint($fingerprint);
        }

        return $markerHmacs;
    }

    /**
     * @return array<string, string>
     */
    private function rotationMetadata(
        string $oldFingerprint,
        string $newFingerprint,
        string $markerSourceFingerprint,
        ?string $predecessorRotationId = null,
    ): array {
        $metadata = [
            'rotation_id' => (string) Str::uuid(),
            'old_target_fingerprint' => $oldFingerprint,
            'new_target_fingerprint' => $newFingerprint,
            'marker_source_fingerprint' => $markerSourceFingerprint,
        ];

        if ($predecessorRotationId !== null) {
            $metadata['predecessor_rotation_id'] = $predecessorRotationId;
        }

        return $metadata;
    }

    /**
     * @return null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }
     */
    private function pendingRotation(Shop $shop): ?array
    {
        $activities = $shop->lifecycleActivities()
            ->whereIn('event', [
                ShopLifecycleEvent::DatabaseEndpointRotationStarted->value,
                ShopLifecycleEvent::DatabaseEndpointRotationCompleted->value,
                ShopLifecycleEvent::DatabaseEndpointRotationSuperseded->value,
            ])
            ->get(['event', 'metadata']);
        $started = [];
        $terminals = [];

        foreach ($activities as $activity) {
            $metadata = $activity->metadata;

            if (! is_array($metadata) || ! $this->isExactRotationMetadata($metadata)) {
                throw $this->rotationStateConflict();
            }

            $rotationId = $metadata['rotation_id'];

            if ($activity->event === ShopLifecycleEvent::DatabaseEndpointRotationStarted) {
                if (array_key_exists($rotationId, $started)) {
                    throw $this->rotationStateConflict();
                }

                $started[$rotationId] = $metadata;
            } else {
                if (array_key_exists($rotationId, $terminals)) {
                    throw $this->rotationStateConflict();
                }

                $terminals[$rotationId] = [
                    'event' => $activity->event,
                    'metadata' => $metadata,
                ];
            }
        }

        foreach ($terminals as $rotationId => $terminal) {
            if (! isset($started[$rotationId])
                || $started[$rotationId] !== $terminal['metadata']) {
                throw $this->rotationStateConflict();
            }
        }

        $successors = [];

        foreach ($started as $rotationId => $metadata) {
            $predecessorRotationId = $metadata['predecessor_rotation_id'] ?? null;

            if ($predecessorRotationId === null) {
                continue;
            }

            $predecessor = $started[$predecessorRotationId] ?? null;
            $predecessorTerminal = $terminals[$predecessorRotationId] ?? null;

            if (! is_array($predecessor)
                || ! is_array($predecessorTerminal)
                || isset($successors[$predecessorRotationId])
                || ! hash_equals(
                    $predecessor['new_target_fingerprint'],
                    $metadata['old_target_fingerprint'],
                )) {
                throw $this->rotationStateConflict();
            }

            $expectedMarkerSource = $predecessorTerminal['event']
                === ShopLifecycleEvent::DatabaseEndpointRotationCompleted
                    ? $predecessor['new_target_fingerprint']
                    : $this->markerSourceFingerprint($predecessor);

            if (! hash_equals(
                $expectedMarkerSource,
                $this->markerSourceFingerprint($metadata),
            )) {
                throw $this->rotationStateConflict();
            }

            $successors[$predecessorRotationId] = $rotationId;
        }

        $this->assertAcyclicRotationGraph($started);

        foreach ($terminals as $rotationId => $terminal) {
            if ($terminal['event'] === ShopLifecycleEvent::DatabaseEndpointRotationSuperseded
                && ! isset($successors[$rotationId])) {
                throw $this->rotationStateConflict();
            }
        }

        $pending = array_diff_key($started, $terminals);

        if (count($pending) > 1) {
            throw $this->rotationStateConflict();
        }

        $metadata = array_values($pending)[0] ?? null;

        if ($metadata === null) {
            return null;
        }

        if (! hash_equals(
            $metadata['new_target_fingerprint'],
            (string) $shop->database_target_fingerprint,
        )) {
            throw $this->rotationStateConflict();
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function isExactRotationMetadata(array $metadata): bool
    {
        $keys = array_keys($metadata);
        sort($keys, SORT_STRING);

        $legacyKeys = [
            'new_target_fingerprint',
            'old_target_fingerprint',
            'rotation_id',
        ];
        $currentKeys = [
            'marker_source_fingerprint',
            'new_target_fingerprint',
            'old_target_fingerprint',
            'rotation_id',
        ];
        $successorKeys = [
            'marker_source_fingerprint',
            'new_target_fingerprint',
            'old_target_fingerprint',
            'predecessor_rotation_id',
            'rotation_id',
        ];

        if (! in_array($keys, [$legacyKeys, $currentKeys, $successorKeys], true)) {
            return false;
        }

        $markerSourceFingerprint = $this->markerSourceFingerprint($metadata);
        $predecessorRotationId = $metadata['predecessor_rotation_id'] ?? null;

        return $this->isUuid($metadata['rotation_id'])
            && is_string($metadata['old_target_fingerprint'])
            && preg_match('/\A[a-f0-9]{64}\z/', $metadata['old_target_fingerprint']) === 1
            && is_string($metadata['new_target_fingerprint'])
            && preg_match('/\A[a-f0-9]{64}\z/', $metadata['new_target_fingerprint']) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', $markerSourceFingerprint) === 1
            && ($predecessorRotationId === null || $this->isUuid($predecessorRotationId))
            && ($predecessorRotationId === null
                || ! hash_equals($metadata['rotation_id'], $predecessorRotationId))
            && ($predecessorRotationId !== null
                || hash_equals($metadata['old_target_fingerprint'], $markerSourceFingerprint))
            && ! hash_equals(
                $metadata['old_target_fingerprint'],
                $metadata['new_target_fingerprint'],
            );
    }

    /** @param array<string, array<string, string>> $started */
    private function assertAcyclicRotationGraph(array $started): void
    {
        foreach (array_keys($started) as $rotationId) {
            $visited = [];
            $currentRotationId = $rotationId;

            while (isset($started[$currentRotationId])) {
                if (isset($visited[$currentRotationId])) {
                    throw $this->rotationStateConflict();
                }

                $visited[$currentRotationId] = true;
                $predecessorRotationId = $started[$currentRotationId]['predecessor_rotation_id']
                    ?? null;

                if ($predecessorRotationId === null) {
                    break;
                }

                $currentRotationId = $predecessorRotationId;
            }
        }
    }

    /**
     * @param array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * } $metadata
     */
    private function markerSourceFingerprint(array $metadata): string
    {
        return $metadata['marker_source_fingerprint'] ?? $metadata['old_target_fingerprint'];
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value,
            ) === 1;
    }

    private function rotationStateConflict(): TenantDatabaseEndpointRotationException
    {
        return TenantDatabaseEndpointRotationException::safe(
            'ROTATION_STATE_CONFLICT',
            'The database endpoint rotation state requires operator review.',
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
            if ($this->addressClassifier->isPublicUnicast($endpoint['address'])) {
                continue;
            }

            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_DESTINATION_FORBIDDEN',
                'The resolved database destination is not permitted.',
            );
        }
    }
}
