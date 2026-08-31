<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_is_reachable(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Sign in');
    }

    public function test_a_user_can_sign_in_with_a_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'counter1',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post(route('login.store'), [
            'username' => 'counter1',
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        User::factory()->create(['username' => 'counter1', 'password' => Hash::make('secret-password')]);

        $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'wrong'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_an_unknown_username_is_rejected(): void
    {
        $this->post(route('login.store'), ['username' => 'ghost', 'password' => 'whatever'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_the_password_is_never_echoed_back_to_the_login_form(): void
    {
        User::factory()->create(['username' => 'counter1']);

        $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('username');

        $this->assertNotSame('wrong-password', session()->getOldInput('password'));
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        User::factory()->inactive()->create([
            'username' => 'ex-employee',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post(route('login.store'), ['username' => 'ex-employee', 'password' => 'secret-password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_repeated_failed_logins_are_rate_limited(): void
    {
        User::factory()->create(['username' => 'counter1', 'password' => Hash::make('secret-password')]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'wrong']);
        }

        $response = $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'wrong']);

        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString(
            'seconds',
            (string) session('errors')->first('username'),
            'the sixth attempt should be throttled with a wait message'
        );
    }

    public function test_a_signed_in_user_can_sign_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_the_session_is_regenerated_on_login_to_prevent_fixation(): void
    {
        User::factory()->create(['username' => 'counter1', 'password' => Hash::make('secret-password')]);

        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'secret-password']);

        $this->assertNotSame($before, session()->getId());
    }

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get(route('pos.create'))->assertRedirect(route('login'));
        $this->get(route('sales.index'))->assertRedirect(route('login'));
        $this->get(route('items.index'))->assertRedirect(route('login'));
        $this->get(route('reports.index'))->assertRedirect(route('login'));
    }

    public function test_passwords_are_stored_hashed_not_in_plain_text(): void
    {
        $user = User::factory()->create(['password' => Hash::make('secret-password')]);

        $this->assertNotSame('secret-password', $user->password);
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }

    public function test_the_password_is_hidden_from_array_and_json_output(): void
    {
        $user = User::factory()->create();

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_every_user_has_one_of_the_three_roles(): void
    {
        $this->assertSame(
            ['admin', 'manager', 'technician'],
            array_column(Role::cases(), 'value')
        );
    }

    public function test_role_helpers_report_the_users_role(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
        $this->assertTrue(User::factory()->manager()->create()->isManager());
        $this->assertTrue(User::factory()->technician()->create()->isTechnician());
        $this->assertFalse(User::factory()->technician()->create()->isAdmin());
    }
    /* ---------------------------------------------------------------- */
    /* Where each role lands after signing in */
    /* ---------------------------------------------------------------- */

    public function test_a_technician_lands_somewhere_they_are_allowed_to_be(): void
    {
        User::factory()->technician()->create([
            'username' => 'mechanic1',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->post(route('login.store'), [
            'username' => 'mechanic1',
            'password' => 'secret-password',
        ]);

        // A technician holds no pos.use, so landing on the counter screen would
        // 403 them out of the app the instant they signed in.
        $this->followingRedirects()->get($response->headers->get('Location'))
            ->assertOk();
    }

    public function test_a_manager_lands_on_the_counter_screen(): void
    {
        User::factory()->manager()->create([
            'username' => 'counter1',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post(route('login.store'), ['username' => 'counter1', 'password' => 'secret-password']);

        $this->followingRedirects()->get(route('home'))
            ->assertOk()
            ->assertSee('Customer &amp; vehicle', false);
    }

    public function test_a_technician_still_lands_somewhere_when_scripts_are_switched_off(): void
    {
        app(ModuleRegistry::class)->setEnabled('scripts', false);

        $this->actingAs(User::factory()->technician()->create());

        // Workshop is still on, so they land there rather than on a 404.
        $this->get(route('home'))->assertRedirect(route('service-history.index'));
    }

    public function test_a_user_with_no_enabled_screens_is_told_so_rather_than_looping(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->setEnabled('scripts', false);
        $registry->setEnabled('workshop', false);

        $this->actingAs(User::factory()->technician()->create());

        $this->get(route('home'))->assertForbidden();
    }
}
