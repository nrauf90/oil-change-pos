<?php

namespace App\Tenancy;

final readonly class PreparedDatabaseEndpointRotation
{
    /**
     * @param  list<string>  $currentClaimFingerprints
     * @param  null|array{
     *     rotation_id: string,
     *     old_target_fingerprint: string,
     *     new_target_fingerprint: string,
     *     marker_source_fingerprint?: string,
     *     predecessor_rotation_id?: string
     * }  $pendingRotation
     * @param  list<array{host: string, address: string, port: int}>  $candidateEndpoints
     * @param  list<string>  $candidateClaimFingerprints
     */
    public function __construct(
        public string $token,
        public string $currentTargetFingerprint,
        public array $currentClaimFingerprints,
        public ?array $pendingRotation,
        public string $candidateTargetFingerprint,
        public array $candidateEndpoints,
        public array $candidateClaimFingerprints,
    ) {}
}
