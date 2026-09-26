<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\FtpAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_date_uses_portuguese_and_instance_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-26 01:32:00', 'UTC'));

        try {
            $this->actingAs(User::factory()->admin()->create());
            $this->get(route('dashboard'))->assertOk()
                ->assertSee('Sexta-feira, 25 de setembro de 2026')
                ->assertSee('22:32');

            DB::table('application_settings')->where('id', 1)->update(['timezone' => 'UTC']);
            $this->get(route('dashboard'))->assertOk()
                ->assertSee('Sábado, 26 de setembro de 2026')
                ->assertSee('01:32');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_displays_recent_ftp_file_sizes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $account = new FtpAccount([
            'account_uuid' => (string) Str::uuid(),
            'purpose' => 'file_server',
            'home_layout' => 'account',
            'username' => 'files_test',
            'is_active' => true,
        ]);
        $account->secret = 'ValidPassword123!';
        $account->save();

        foreach ([1258291, 0, null] as $index => $bytes) {
            DB::table('ftp_received_files')->insert([
                'ftp_account_id' => $account->id,
                'claim_token' => str_repeat((string) ($index + 1), 32),
                'original_filename' => 'arquivo-'.$index.'.bin',
                'size_bytes' => $bytes,
                'status' => 'stored',
                'received_at' => now()->subMinutes($index),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Tamanho')
            ->assertSee('1,2 MB')
            ->assertSee('0 B')
            ->assertSee('class="size-cell">—</td>', false);
    }
}
