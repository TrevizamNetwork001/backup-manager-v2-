<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FtpAccessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FtpAccessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_canonical_cidrs_and_sees_pending_application(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('settings.ftp-access.update'), [
            'ftp_allowed_cidrs' => "10.2.3.4/8\n45.239.156.0/22\n10.0.0.0/8\n",
        ])->assertRedirect();

        $settings = app(FtpAccessSettings::class)->get();
        $this->assertSame(['10.0.0.0/8', '45.239.156.0/22'], $settings['cidrs']);
        $this->assertSame(1, $settings['revision']);
        $this->assertSame(0, $settings['applied_revision']);
        $this->get(route('settings.edit'))->assertOk()->assertSee('Aguardando aplicação');
    }

    public function test_invalid_or_unrestricted_ranges_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['0.0.0.0/0', '45.239.156.0/33', '2001:db8::/32', 'not-an-ip'] as $input) {
            $this->put(route('settings.ftp-access.update'), ['ftp_allowed_cidrs' => $input])
                ->assertSessionHasErrors('ftp_allowed_cidrs');
        }
        $this->assertSame(0, app(FtpAccessSettings::class)->get()['revision']);
    }

    public function test_viewer_cannot_change_ftp_source_list(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $this->put(route('settings.ftp-access.update'), ['ftp_allowed_cidrs' => '10.0.0.0/8'])
            ->assertForbidden();
    }
}
