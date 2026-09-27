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

    public function test_login_accepts_a_unique_user_name(): void
    {
        $user = User::factory()->admin()->create(['name' => 'Operador Backup', 'password' => 'ValidPassword123!']);

        $this->post(route('login.store'), ['email' => 'Operador Backup', 'password' => 'ValidPassword123!'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_rejects_an_ambiguous_user_name(): void
    {
        User::factory()->admin()->create(['name' => 'Operador Backup', 'password' => 'ValidPassword123!']);
        User::factory()->admin()->create(['name' => 'Operador Backup', 'password' => 'ValidPassword123!']);

        $this->post(route('login.store'), ['email' => 'Operador Backup', 'password' => 'ValidPassword123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_keeps_the_public_proxy_port_in_form_and_redirect(): void
    {
        $user = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        $this->withServerVariables([
            'HTTP_HOST' => 'backup.trevizamnetwork.com.br',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $this->get('/login')
            ->assertSee('action="https://backup.trevizamnetwork.com.br:8443/login"', false);
        $this->post('/login', ['email' => $user->email, 'password' => 'ValidPassword123!'])
            ->assertRedirect('https://backup.trevizamnetwork.com.br:8443');
    }
}
