<?php

namespace Tests\Feature\Console;

use App\Models\Central\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Platform\PlatformTestCase;

class PlatformMakeSuperAdminCommandTest extends PlatformTestCase
{
    public function test_non_interactive_input_creates_an_active_super_admin_without_exposing_the_password(): void
    {
        $password = 'a-long-password-for-automation';

        $this->artisan('platform:make-super-admin', [
            '--name' => 'Platform Administrator',
            '--email' => 'admin@example.test',
            '--password' => $password,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Platform super administrator created.')
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $platformUser = PlatformUser::query()->where('email', 'admin@example.test')->sole();

        $this->assertSame('Platform Administrator', $platformUser->name);
        $this->assertSame(PlatformUser::ROLE_SUPER_ADMIN, $platformUser->role);
        $this->assertTrue($platformUser->is_active);
        $this->assertTrue(Hash::check($password, $platformUser->password));
    }

    public function test_invalid_input_creates_no_platform_user(): void
    {
        $this->artisan('platform:make-super-admin', [
            '--name' => 'Platform Administrator',
            '--email' => 'not-an-email',
            '--password' => 'a-long-password-for-automation',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('The email field must be a valid email address.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('platform_users', 0, 'central');
    }

    public function test_interactive_input_confirms_the_hidden_password_before_creating_the_super_admin(): void
    {
        $password = 'a-long-password-for-interactive-input';

        $this->artisan('platform:make-super-admin')
            ->expectsQuestion('Name', 'Platform Administrator')
            ->expectsQuestion('Email', 'admin@example.test')
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $password)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $platformUser = PlatformUser::query()->where('email', 'admin@example.test')->sole();

        $this->assertTrue(Hash::check($password, $platformUser->password));
    }

    public function test_duplicate_email_leaves_the_existing_platform_user_unchanged(): void
    {
        $existingUser = PlatformUser::factory()->create([
            'name' => 'Existing Administrator',
            'email' => 'admin@example.test',
            'password' => 'existing-password',
        ]);

        $this->artisan('platform:make-super-admin', [
            '--name' => 'Replacement Administrator',
            '--email' => $existingUser->email,
            '--password' => 'a-long-password-for-automation',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('A platform user with this email already exists.')
            ->assertExitCode(2);

        $this->assertDatabaseCount('platform_users', 1, 'central');
        $this->assertSame('Existing Administrator', $existingUser->fresh()->name);
        $this->assertTrue(Hash::check('existing-password', $existingUser->fresh()->password));
    }
}
