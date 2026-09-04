<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    public function test_a_spray_across_many_usernames_from_one_address_is_locked_out(): void
    {
        User::factory()->create([
            'username' => 'counter1',
            'password' => Hash::make('secret-password'),
        ]);

        // Each username gets its own five attempts, so twenty wrong guesses
        // spread across twenty names never trips the per-username bucket.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'ghost'.$attempt,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'username' => 'counter1',
            'password' => 'secret-password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_an_address_below_the_limit_can_still_sign_in(): void
    {
        $user = User::factory()->create([
            'username' => 'counter1',
            'password' => Hash::make('secret-password'),
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'ghost'.$attempt,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'username' => 'counter1',
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_successful_sign_in_clears_the_address_bucket(): void
    {
        User::factory()->create([
            'username' => 'counter1',
            'password' => Hash::make('secret-password'),
        ]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'ghost'.$attempt,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'username' => 'counter1',
            'password' => 'secret-password',
        ]);
        $this->post(route('logout'));

        for ($attempt = 11; $attempt <= 19; $attempt++) {
            $this->post(route('login.store'), [
                'username' => 'ghost'.$attempt,
                'password' => 'wrong-password',
            ]);
        }

        $this->post(route('login.store'), [
            'username' => 'counter1',
            'password' => 'secret-password',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }
}
