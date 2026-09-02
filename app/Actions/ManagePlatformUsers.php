<?php

namespace App\Actions;

use App\Models\Central\PlatformUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;

class ManagePlatformUsers
{
    /** @param array<string, mixed> $data */
    public function create(PlatformUser $actor, array $data): PlatformUser
    {
        return (new PlatformUser)->getConnection()->transaction(function () use ($actor, $data): PlatformUser {
            $this->authorizeActor($this->lockRelevantUsers($actor->getKey()), $actor->getKey());

            $platformUser = new PlatformUser(Arr::only($data, ['name', 'email', 'password']));
            $platformUser->forceFill([
                'role' => PlatformUser::ROLE_SUPER_ADMIN,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $platformUser->save();

            return $platformUser;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PlatformUser $actor, PlatformUser $record, array $data): PlatformUser
    {
        return (new PlatformUser)->getConnection()->transaction(function () use ($actor, $record, $data): PlatformUser {
            $platformUsers = $this->lockRelevantUsers($actor->getKey(), $record->getKey());
            $this->authorizeActor($platformUsers, $actor->getKey());
            $platformUser = $this->managedUser($platformUsers, $record->getKey());
            $isActive = (bool) ($data['is_active'] ?? $platformUser->is_active);

            if ($isActive !== $platformUser->is_active) {
                if ($isActive) {
                    $platformUser->activate();
                } else {
                    $platformUser->deactivate();
                }
            }

            $platformUser->fill(Arr::only($data, ['name', 'email', 'password']));
            $platformUser->save();

            return $platformUser;
        });
    }

    public function delete(PlatformUser $actor, PlatformUser $record): bool
    {
        return (new PlatformUser)->getConnection()->transaction(function () use ($actor, $record): bool {
            $platformUsers = $this->lockRelevantUsers($actor->getKey(), $record->getKey());
            $this->authorizeActor($platformUsers, $actor->getKey());
            $platformUser = $this->managedUser($platformUsers, $record->getKey());

            return $platformUser->delete() === true;
        });
    }

    /** @return Collection<int, PlatformUser> */
    private function lockRelevantUsers(int|string $actorKey, int|string|null $recordKey = null): Collection
    {
        $keyName = (new PlatformUser)->getKeyName();
        $keys = array_values(array_unique(array_filter(
            [$actorKey, $recordKey],
            static fn (int|string|null $key): bool => $key !== null,
        )));

        return PlatformUser::query()
            ->where(static function (Builder $query) use ($keyName, $keys): void {
                $query->where('role', PlatformUser::ROLE_SUPER_ADMIN)
                    ->orWhereIn($keyName, $keys);
            })
            ->orderBy($keyName)
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, PlatformUser> $platformUsers */
    private function authorizeActor(Collection $platformUsers, int|string $actorKey): PlatformUser
    {
        $actor = $platformUsers->first(
            static fn (PlatformUser $platformUser): bool => (string) $platformUser->getKey() === (string) $actorKey,
        );

        if (! $actor instanceof PlatformUser
            || ! $actor->is_active
            || $actor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
            throw new AuthorizationException('Your platform administrator access is no longer active.');
        }

        return $actor;
    }

    /** @param Collection<int, PlatformUser> $platformUsers */
    private function managedUser(Collection $platformUsers, int|string $recordKey): PlatformUser
    {
        $platformUser = $platformUsers->first(
            static fn (PlatformUser $candidate): bool => (string) $candidate->getKey() === (string) $recordKey,
        );

        if (! $platformUser instanceof PlatformUser
            || $platformUser->role !== PlatformUser::ROLE_SUPER_ADMIN) {
            throw (new ModelNotFoundException)->setModel(PlatformUser::class, [$recordKey]);
        }

        return $platformUser;
    }
}
