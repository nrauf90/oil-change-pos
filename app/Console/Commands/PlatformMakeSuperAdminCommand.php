<?php

namespace App\Console\Commands;

use App\Models\Central\PlatformUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('platform:make-super-admin
    {--name= : Full name for the platform administrator}
    {--email= : Email address for the platform administrator}
    {--password= : Password for non-interactive automation}')]
#[Description('Create the first active platform super administrator')]
class PlatformMakeSuperAdminCommand extends Command
{
    public function handle(): int
    {
        $attributes = $this->attributes();

        if ($attributes === null) {
            return self::INVALID;
        }

        try {
            $created = DB::connection('central')->transaction(function () use ($attributes): bool {
                if (PlatformUser::query()->where('email', $attributes['email'])->exists()) {
                    return false;
                }

                $platformUser = new PlatformUser;
                $platformUser->forceFill([
                    ...$attributes,
                    'password' => Hash::make($attributes['password']),
                    'role' => PlatformUser::ROLE_SUPER_ADMIN,
                    'is_active' => true,
                ])->save();

                return true;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateEmailConstraintViolation($exception)) {
                $this->components->error('A platform user with this email already exists.');

                return self::INVALID;
            }

            $this->components->error('Unable to create the platform super administrator.');

            return self::FAILURE;
        }

        if (! $created) {
            $this->components->error('A platform user with this email already exists.');

            return self::INVALID;
        }

        $this->components->info('Platform super administrator created.');

        return self::SUCCESS;
    }

    /** @return array{name: mixed, email: mixed, password: mixed}|null */
    private function attributes(): ?array
    {
        $name = $this->option('name');
        $email = $this->option('email');
        $password = $this->option('password');

        if ($this->input->isInteractive()) {
            $name ??= $this->ask('Name');
            $email ??= $this->ask('Email');

            if ($password === null) {
                $password = $this->secret('Password');
                $confirmation = $this->secret('Confirm password');

                if (! is_string($password)
                    || ! is_string($confirmation)
                    || ! hash_equals($password, $confirmation)) {
                    $this->components->error('The password confirmation does not match.');

                    return null;
                }
            }
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return null;
        }

        return $validator->validated();
    }

    private function isDuplicateEmailConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'platform_users.email');
    }
}
