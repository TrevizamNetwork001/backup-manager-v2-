<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionHttpTest extends TestCase
{
    use RefreshDatabase;

    private function proxyHeaders(): array
    {
        return [
            'REMOTE_ADDR' => '172.18.0.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.100',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
        ];
    }

    public function test_trusted_proxy_preserves_https_port_and_secure_session_cookies(): void
    {
        config()->set('session.secure', true);
        $response = $this->call('GET', 'http://backup.trevizamnetwork.com.br/login', [], [], [], $this->proxyHeaders());

        $response->assertOk()->assertSee('https://backup.trevizamnetwork.com.br:8443/login', false);
        $response->assertDontSee('untrusted.example');
        $session = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($session);
        $this->assertTrue($session->isSecure());
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertSame('192.0.2.100', request()->ip());
    }

    public function test_untrusted_peer_cannot_spoof_the_scheme_port_or_client_ip(): void
    {
        $server = $this->proxyHeaders();
        $server['REMOTE_ADDR'] = '203.0.113.10';
        $this->call('GET', 'http://backup.trevizamnetwork.com.br:8081/login', [], [], [], $server)->assertOk();

        $this->assertFalse(request()->isSecure());
        $this->assertSame(8081, request()->getPort());
        $this->assertSame('203.0.113.10', request()->ip());
    }

    public function test_login_and_logout_keep_the_v2_https_destination_behind_the_proxy(): void
    {
        $user = User::factory()->admin()->create(['password' => 'ValidPassword123!']);

        $this->call('POST', 'http://backup.trevizamnetwork.com.br/login', [
            'email' => $user->email, 'password' => 'ValidPassword123!',
        ], [], [], $this->proxyHeaders())->assertRedirect('https://backup.trevizamnetwork.com.br:8443');
        $this->assertAuthenticatedAs($user);

        $this->call('POST', 'http://backup.trevizamnetwork.com.br/logout', [], [], [], $this->proxyHeaders())
            ->assertRedirect('https://backup.trevizamnetwork.com.br:8443/login');
        $this->assertGuest();
    }

    public function test_production_login_rejects_missing_csrf_and_accepts_a_valid_token(): void
    {
        $user = User::factory()->admin()->create(['password' => 'ValidPassword123!']);
        $credentials = ['email' => $user->email, 'password' => 'ValidPassword123!'];
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->call('POST', 'http://backup.trevizamnetwork.com.br/login', $credentials, [], [], $this->proxyHeaders())
                ->assertStatus(419);
            $this->assertGuest();
            $this->withSession(['_token' => 'release-synthetic-csrf']);
            $this->call('POST', 'http://backup.trevizamnetwork.com.br/login', $credentials + ['_token' => 'release-synthetic-csrf'], [], [], $this->proxyHeaders())
                ->assertRedirect('https://backup.trevizamnetwork.com.br:8443');
            $this->assertAuthenticatedAs($user);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
