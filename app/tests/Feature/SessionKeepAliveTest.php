<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionKeepAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post(route('session.keep-alive'))->assertRedirect('/login');
    }

    public function test_authenticated_user_receives_no_content(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->post(route('session.keep-alive'))->assertNoContent();
    }

    public function test_dashboard_ships_the_timeout_modal_and_csrf_meta_tag(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="session-timeout-modal"', false)
            ->assertSee('csrf-token', false);
    }
}
