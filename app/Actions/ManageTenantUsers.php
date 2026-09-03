<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantSessionAuthentication;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;

final class ManageTenantUsers
{
    public function __construct(
        private readonly TenantSessionAuthentication $sessionAuthentication,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array<string, mixed> $data */
    public function update(User $actor, User $record, array $data, string $roleName): User
    {
        return $this->connection()->transaction(function () use ($actor, $record, $data, $roleName): User {
            [$users, $administratorRole] = $this->lockMutationState(
                $actor->getKey(),
                $record->getKey(),
            );
            $freshActor = $this->authorizeActor($users, $actor->getKey(), Permission::UpdateUser);
            $user = $this->managedUser($users, $record->getKey());
            $role = Role::query()
                ->where('guard_name', 'web')
                ->where('name', $roleName)
                ->lockForUpdate()
                ->first();

            if (! $role instanceof Role) {
                throw new LogicException('Choose a valid role for this shop.');
            }

            $willBeActive = (bool) ($data['is_active'] ?? $user->is_active);
            $currentRoleName = $user->roles->first()?->name;

            if ($user->is($freshActor)) {
                if (! $willBeActive) {
                    throw new LogicException('You cannot deactivate your own signed-in account.');
                }

                if ($role->name !== $currentRoleName) {
                    throw new LogicException('You cannot change the role of your own signed-in account.');
                }
            }

            if ($this->isActiveAdministrator($user, $administratorRole)
                && (! $willBeActive || ! $role->is($administratorRole))
                && ! $this->hasOtherActiveAdministrator($users, $user, $administratorRole)) {
                throw new LogicException(! $willBeActive
                    ? 'This is the last active admin. Promote or activate another admin before deactivating this one.'
                    : 'This is the last active admin. Promote another staff member to admin before changing this role.');
            }

            $wasActive = (bool) $user->is_active;
            $passwordIsChanging = array_key_exists('password', $data);
            $requiresCredentialRevocation = $passwordIsChanging || ! $wasActive || ! $willBeActive;

            if ($requiresCredentialRevocation) {
                $user->setRememberToken(Str::random(60));
            }

            $user->fill(Arr::only($data, ['name', 'username', 'password', 'is_active']));
            $user->save();
            $user->assignSingleRole($role);

            if ($requiresCredentialRevocation) {
                $this->sessionAuthentication->revokePersistedSessions(
                    $this->tenantContext->id(),
                    $user->getKey(),
                );
            }

            return $user->load('roles');
        });
    }

    public function delete(User $actor, User $record): bool
    {
        return $this->connection()->transaction(function () use ($actor, $record): bool {
            [$users, $administratorRole] = $this->lockMutationState(
                $actor->getKey(),
                $record->getKey(),
            );
            $freshActor = $this->authorizeActor($users, $actor->getKey(), Permission::DeleteUser);
            $user = $this->managedUser($users, $record->getKey());

            if ($user->is($freshActor)) {
                throw new LogicException('You cannot delete your own signed-in account.');
            }

            if ($this->isActiveAdministrator($user, $administratorRole)
                && ! $this->hasOtherActiveAdministrator($users, $user, $administratorRole)) {
                throw new LogicException(
                    'This is the last active admin. Add or activate another admin before deleting this account.',
                );
            }

            $user->setRememberToken(Str::random(60));
            $user->saveQuietly();
            $this->sessionAuthentication->revokePersistedSessions(
                $this->tenantContext->id(),
                $user->getKey(),
            );

            return $user->delete() === true;
        });
    }

    /**
     * @return array{Collection<int, User>, Role}
     */
    private function lockMutationState(int|string $actorKey, int|string $recordKey): array
    {
        $administratorRole = $this->lockAdministratorSerializationRow();
        $keyName = (new User)->getKeyName();
        $keys = array_values(array_unique([$actorKey, $recordKey]));
        $users = User::query()
            ->where(static function (Builder $query) use ($keyName, $keys): void {
                $query->whereIn($keyName, $keys)
                    ->orWhere(static function (Builder $query): void {
                        $query->where('is_active', true)
                            ->whereHas('roles', static fn (Builder $roleQuery): Builder => $roleQuery
                                ->where('name', RoleEnum::Admin->value)
                                ->where('guard_name', 'web'));
                    });
            })
            ->orderBy($keyName)
            ->lockForUpdate()
            ->get();
        $users->load(['permissions', 'roles.permissions']);

        return [$users, $administratorRole];
    }

    /**
     * SQLite ignores FOR UPDATE, so the no-op write is its equivalent
     * cross-process serialization boundary. MySQL takes the same row lock.
     */
    private function lockAdministratorSerializationRow(): Role
    {
        $roleModel = new Role;
        $connection = $roleModel->getConnection();
        $connection->table($roleModel->getTable())
            ->where('guard_name', 'web')
            ->where('name', RoleEnum::Admin->value)
            ->update(['updated_at' => $connection->raw('updated_at')]);

        return Role::query()
            ->where('guard_name', 'web')
            ->where('name', RoleEnum::Admin->value)
            ->lockForUpdate()
            ->sole();
    }

    /** @param Collection<int, User> $users */
    private function authorizeActor(
        Collection $users,
        int|string $actorKey,
        Permission $permission,
    ): User {
        $actor = $this->findUser($users, $actorKey);

        if (! $actor instanceof User
            || ! $actor->is_active
            || ! $actor->can($permission->value)) {
            throw new AuthorizationException('Your staff management access is no longer active.');
        }

        return $actor;
    }

    /** @param Collection<int, User> $users */
    private function managedUser(Collection $users, int|string $recordKey): User
    {
        $user = $this->findUser($users, $recordKey);

        if (! $user instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$recordKey]);
        }

        return $user;
    }

    /** @param Collection<int, User> $users */
    private function findUser(Collection $users, int|string $key): ?User
    {
        return $users->first(
            static fn (User $user): bool => (string) $user->getKey() === (string) $key,
        );
    }

    private function isActiveAdministrator(User $user, Role $administratorRole): bool
    {
        return $user->is_active && $user->roles->contains(
            static fn (Role $role): bool => $role->is($administratorRole),
        );
    }

    /** @param Collection<int, User> $users */
    private function hasOtherActiveAdministrator(
        Collection $users,
        User $record,
        Role $administratorRole,
    ): bool {
        return $users->contains(
            fn (User $user): bool => ! $user->is($record)
                && $this->isActiveAdministrator($user, $administratorRole),
        );
    }

    private function connection(): Connection
    {
        return (new User)->getConnection();
    }
}
