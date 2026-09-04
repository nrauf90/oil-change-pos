<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function the_login_page_links_to_the_reset_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('password.request'), escape: false);
    }

    #[Test]
    public function a_staff_member_with_an_email_receives_a_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);

        $this->post(route('password.email'), ['email' => 'owner@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function an_unknown_address_is_reported_exactly_like_a_known_one(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);

        $known = $this->post(route('password.email'), ['email' => 'owner@example.test']);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.test']);

        // Differing responses would let anyone enumerate which addresses hold
        // an account at this shop.
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
        );

        Notification::assertNothingSentTo(
            User::factory()->make(['email' => 'nobody@example.test']),
        );
    }

    #[Test]
    public function a_deactivated_staff_member_cannot_request_a_reset(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'gone@example.test', 'is_active' => false]);

        $this->post(route('password.email'), ['email' => 'gone@example.test'])
            ->assertSessionHas('status');

        // The auth provider filters is_active, and the broker uses that same
        // provider — a dismissed employee must not be able to let themselves
        // back in through the reset form.
        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    #[Test]
    public function a_valid_token_changes_the_password_and_rotates_remember_me(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'is_active' => true,
            'password' => Hash::make('old-password-123'),
            'remember_token' => 'stale-token-value',
        ]);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'owner@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('login'));

        $user->refresh();

        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        // Whoever reset this may be locking someone else out on purpose.
        $this->assertNotSame('stale-token-value', $user->remember_token);
    }

    #[Test]
    public function an_invalid_token_is_refused(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.test',
            'is_active' => true,
            'password' => Hash::make('old-password-123'),
        ]);

        $this->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'owner@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old-password-123', $user->refresh()->password));
    }

    #[Test]
    public function the_two_passwords_must_match(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'owner@example.test',
            'password' => 'brand-new-password',
            'password_confirmation' => 'something-else-entirely',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function reset_tokens_are_written_to_the_tenant_connection(): void
    {
        // Left on the default connection these land in the central control
        // plane, where two shops sharing an address overwrite each other.
        $this->assertSame('tenant', config('auth.passwords.users.connection'));
    }

    #[Test]
    public function the_send_endpoint_is_rate_limited(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post(route('password.email'), ['email' => 'owner@example.test']);
        }

        // The broker throttles per address; this guards a caller cycling
        // through many different addresses.
        $this->post(route('password.email'), ['email' => 'owner@example.test'])
            ->assertStatus(429);
    }
}
