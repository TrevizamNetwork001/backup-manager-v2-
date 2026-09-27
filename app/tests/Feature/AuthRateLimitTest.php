<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * STABILIZATION-1 (P1 finding): login previously had no rate limiting at all.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('login');
        parent::tearDown();
    }

    public function test_sixth_attempt_within_a_minute_is_throttled(): void
    {
        $user = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
    }

    public function test_throttle_key_combines_email_and_ip_not_ip_alone(): void
    {
        $victim = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        $other = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), ['email' => $victim->email, 'password' => 'wrong-password']);
        }
        // A different account from the same IP must not be locked out by the attacker's attempts.
        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'ValidPassword123!'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_successful_login_under_the_limit_is_unaffected(): void
    {
        $user = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'ValidPassword123!'])
            ->assertRedirect(route('dashboard'));
    }
}
