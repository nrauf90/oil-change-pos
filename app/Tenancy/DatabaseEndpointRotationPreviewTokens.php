<?php

namespace App\Tenancy;

use App\Exceptions\TenantDatabaseEndpointRotationException;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Str;
use Throwable;

final readonly class DatabaseEndpointRotationPreviewTokens
{
    private const EXPIRES_AFTER_SECONDS = 300;

    private const PURPOSE = 'tenant-database-endpoint-rotation-preview';

    private const SESSION_KEY = 'tenant_database_endpoint_rotation_preview_digests';

    public function __construct(
        private Encrypter $encrypter,
        private SessionManager $sessions,
    ) {}

    /**
     * @param  list<string>  $currentClaimFingerprints
     * @param  null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }  $pendingRotation
     */
    public function issue(
        PlatformUser $actor,
        Shop $shop,
        string $currentTargetFingerprint,
        array $currentClaimFingerprints,
        ?array $pendingRotation,
        NormalizedDatabaseTarget $candidate,
    ): PreparedDatabaseEndpointRotation {
        $currentClaimFingerprints = $this->sortedStrings($currentClaimFingerprints);
        $pendingRotation = $this->sortedTuple($pendingRotation);
        $candidateEndpoints = $this->sortedEndpoints($candidate->mysqlEndpoints);
        $candidateClaimFingerprints = $this->sortedStrings($candidate->claimFingerprints());
        $issuedAt = now()->getTimestamp();
        $payload = [
            'actor_id' => (string) $actor->getKey(),
            'candidate_claim_fingerprints' => $candidateClaimFingerprints,
            'candidate_endpoints' => $candidateEndpoints,
            'candidate_target_fingerprint' => $candidate->fingerprint,
            'current_claim_fingerprints' => $currentClaimFingerprints,
            'current_target_fingerprint' => $currentTargetFingerprint,
            'expires_at' => $issuedAt + self::EXPIRES_AFTER_SECONDS,
            'issued_at' => $issuedAt,
            'nonce' => (string) Str::uuid(),
            'pending_rotation' => $pendingRotation,
            'purpose' => self::PURPOSE,
            'session_id_hash' => $this->sessionIdHash(),
            'shop_id' => (string) $shop->getKey(),
            'version' => 1,
        ];
        $token = $this->encrypter->encryptString(json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        $digests = $this->storedDigests();
        $digests[(string) $shop->getKey()] = hash('sha256', $token);
        $this->session()->put(self::SESSION_KEY, $digests);

        return new PreparedDatabaseEndpointRotation(
            token: $token,
            currentTargetFingerprint: $currentTargetFingerprint,
            currentClaimFingerprints: $currentClaimFingerprints,
            pendingRotation: $pendingRotation,
            candidateTargetFingerprint: $candidate->fingerprint,
            candidateEndpoints: $candidateEndpoints,
            candidateClaimFingerprints: $candidateClaimFingerprints,
        );
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
     */
    public function consume(
        PlatformUser $actor,
        Shop $shop,
        #[\SensitiveParameter]
        string $token,
        string $currentTargetFingerprint,
        array $currentClaimFingerprints,
        ?array $pendingRotation,
        NormalizedDatabaseTarget $candidate,
    ): void {
        $expectedDigest = $this->consumeStoredDigest((string) $shop->getKey());

        if ($expectedDigest === null || ! hash_equals($expectedDigest, hash('sha256', $token))) {
            throw $this->invalidPreview();
        }

        try {
            $payload = json_decode(
                $this->encrypter->decryptString($token),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            throw $this->invalidPreview();
        }

        if (! is_array($payload) || ! $this->hasExactPayloadShape($payload)) {
            throw $this->invalidPreview();
        }

        if (! hash_equals(self::PURPOSE, $payload['purpose'])
            || $payload['version'] !== 1
            || ! hash_equals((string) $actor->getKey(), $payload['actor_id'])
            || ! hash_equals((string) $shop->getKey(), $payload['shop_id'])
            || ! hash_equals($this->sessionIdHash(), $payload['session_id_hash'])) {
            throw $this->invalidPreview();
        }

        $now = now()->getTimestamp();

        if ($payload['expires_at'] <= $now
            || $payload['issued_at'] > $now
            || $payload['expires_at'] - $payload['issued_at'] > self::EXPIRES_AFTER_SECONDS) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_PREVIEW_EXPIRED',
                'The database endpoint preview expired. Reopen the rotation and confirm again.',
            );
        }

        if (! hash_equals($currentTargetFingerprint, $payload['current_target_fingerprint'])
            || $this->sortedStrings($currentClaimFingerprints) !== $payload['current_claim_fingerprints']
            || $this->sortedTuple($pendingRotation) !== $payload['pending_rotation']
            || ! hash_equals($candidate->fingerprint, $payload['candidate_target_fingerprint'])
            || $this->sortedEndpoints($candidate->mysqlEndpoints) !== $payload['candidate_endpoints']
            || $this->sortedStrings($candidate->claimFingerprints()) !== $payload['candidate_claim_fingerprints']) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_PREVIEW_STALE',
                'The database endpoint changed after preview. Reopen the rotation and confirm the new target.',
            );
        }
    }

    /** @return array<string, string> */
    private function storedDigests(): array
    {
        $digests = $this->session()->get(self::SESSION_KEY, []);

        if (! is_array($digests)) {
            return [];
        }

        return array_filter(
            $digests,
            static fn (mixed $digest, mixed $shopId): bool => is_string($shopId)
                && is_string($digest)
                && preg_match('/\A[a-f0-9]{64}\z/', $digest) === 1,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private function consumeStoredDigest(string $shopId): ?string
    {
        $digests = $this->storedDigests();
        $digest = $digests[$shopId] ?? null;
        unset($digests[$shopId]);
        $this->session()->put(self::SESSION_KEY, $digests);

        return is_string($digest) ? $digest : null;
    }

    private function sessionIdHash(): string
    {
        $session = $this->session();

        if (! $session->isStarted()) {
            $session->start();
        }

        return hash('sha256', $session->getId());
    }

    private function session(): Session
    {
        return $this->sessions->driver();
    }

    /** @param list<string> $values */
    private function sortedStrings(array $values): array
    {
        sort($values, SORT_STRING);

        return array_values($values);
    }

    /**
     * @param  null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }  $tuple
     * @return null|array<string, string>
     */
    private function sortedTuple(?array $tuple): ?array
    {
        if ($tuple !== null) {
            ksort($tuple, SORT_STRING);
        }

        return $tuple;
    }

    /**
     * @param  list<array{host: string, address: string, port: int}>  $endpoints
     * @return list<array{host: string, address: string, port: int}>
     */
    private function sortedEndpoints(array $endpoints): array
    {
        usort(
            $endpoints,
            static fn (array $left, array $right): int => json_encode($left, JSON_THROW_ON_ERROR)
                <=> json_encode($right, JSON_THROW_ON_ERROR),
        );

        return array_values($endpoints);
    }

    /** @param array<string, mixed> $payload */
    private function hasExactPayloadShape(array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);

        if ($keys !== [
            'actor_id',
            'candidate_claim_fingerprints',
            'candidate_endpoints',
            'candidate_target_fingerprint',
            'current_claim_fingerprints',
            'current_target_fingerprint',
            'expires_at',
            'issued_at',
            'nonce',
            'pending_rotation',
            'purpose',
            'session_id_hash',
            'shop_id',
            'version',
        ]) {
            return false;
        }

        return is_string($payload['actor_id'])
            && is_array($payload['candidate_claim_fingerprints'])
            && is_array($payload['candidate_endpoints'])
            && is_string($payload['candidate_target_fingerprint'])
            && is_array($payload['current_claim_fingerprints'])
            && is_string($payload['current_target_fingerprint'])
            && is_int($payload['expires_at'])
            && is_int($payload['issued_at'])
            && is_string($payload['nonce'])
            && ($payload['pending_rotation'] === null || is_array($payload['pending_rotation']))
            && is_string($payload['purpose'])
            && is_string($payload['session_id_hash'])
            && is_string($payload['shop_id'])
            && is_int($payload['version']);
    }

    private function invalidPreview(): TenantDatabaseEndpointRotationException
    {
        return TenantDatabaseEndpointRotationException::safe(
            'ROTATION_PREVIEW_INVALID',
            'The database endpoint preview is invalid. Reopen the rotation and try again.',
        );
    }
}
