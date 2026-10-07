<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EngineHealth;
use App\Services\NotificationManager;
use App\Services\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private function configure(array $extra = []): void
    {
        app(NotificationSettings::class)->save(array_merge(['enabled' => true, 'chat_id' => '-100123'], $extra), self::TOKEN);
    }

    private function fakeConditions(array $conditions): NotificationManager
    {
        $manager = \Mockery::mock(NotificationManager::class, [
            app(NotificationSettings::class), app(EngineHealth::class),
            app(\App\Services\DeviceBackupHealth::class), app(\App\Services\InstanceTimezone::class),
            app(\App\Services\NotificationSummary::class),
        ])->makePartial();
        $manager->shouldReceive('conditions')->andReturnUsing(fn () => $conditions);

        return $manager;
    }

    public function test_token_is_stored_encrypted_and_never_rendered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('settings.notifications.update'), [
            'enabled' => 1, 'bot_token' => self::TOKEN, 'chat_id' => '-100123', 'cooldown_minutes' => 60,
            'maintenance_start' => '22:00', 'maintenance_end' => '06:00',
        ])->assertRedirect();

        $stored = DB::table('notification_settings')->value('bot_token');
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, app(NotificationSettings::class)->token());
        $this->get(route('settings.notifications.edit'))->assertOk()->assertDontSee(self::TOKEN)->assertSee('Configurado');
        $this->assertStringNotContainsString(self::TOKEN, DB::table('audit_events')->pluck('metadata')->implode(''));
    }

    public function test_admin_can_reveal_token_audited_and_viewer_cannot(): void
    {
        $this->configure();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson(route('settings.notifications.token.reveal'))
            ->assertOk()->assertJson(['token' => self::TOKEN])->assertHeader('Cache-Control');
        $this->assertDatabaseHas('audit_events', ['action' => 'notifications.token_revealed']);
        $this->assertStringNotContainsString(self::TOKEN, DB::table('audit_events')->pluck('metadata')->implode(''));

        $this->actingAs(User::factory()->viewer()->create());
        $this->postJson(route('settings.notifications.token.reveal'))->assertForbidden();
    }

    public function test_cannot_enable_without_token_and_viewer_cannot_change(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('settings.notifications.update'), [
            'enabled' => 1, 'cooldown_minutes' => 60, 'maintenance_start' => '22:00', 'maintenance_end' => '06:00',
        ])->assertSessionHasErrors('enabled');

        $this->actingAs(User::factory()->viewer()->create());
        $this->put(route('settings.notifications.update'), [
            'cooldown_minutes' => 60, 'maintenance_start' => '22:00', 'maintenance_end' => '06:00',
        ])->assertForbidden();
    }

    public function test_alert_cooldown_and_recovery_cycle(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(['cooldown_minutes' => 60]);
        $condition = ['system:engine_down' => ['label' => 'Motor parado', 'severity' => 'critical', 'reason' => 'engine_down', 'detail' => '']];

        $manager = $this->fakeConditions($condition);
        $this->assertSame(1, $manager->run()['queued']);
        $this->assertSame(0, $manager->run()['queued'], 'cooldown suppresses repeats');

        $this->travel(61)->minutes();
        $this->assertSame(1, $manager->run()['queued'], 're-alert after cooldown');

        $recovered = $this->fakeConditions([]);
        $this->assertSame(1, $recovered->run()['queued']);
        $this->assertSame(0, $recovered->run()['queued']);
        $this->assertDatabaseHas('notification_queue', ['kind' => 'recovery', 'status' => 'sent']);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Normalizado') && $r['parse_mode'] === 'HTML');
    }

    public function test_three_or_more_new_alerts_are_grouped_and_recovered_as_one(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure();
        $conditions = [];
        foreach ([1, 2, 3, 4] as $id) {
            $conditions["device:{$id}"] = ['label' => "Backup com problema: EQ{$id}", 'severity' => 'critical', 'reason' => 'ftp_backup_stale', 'detail' => 'Envio FTP muito atrasado.'];
        }
        $this->assertSame(1, $this->fakeConditions($conditions)->run()['queued']);
        $this->assertDatabaseHas('notification_queue', ['title' => '4 alertas novos', 'severity' => 'critical']);
        $this->assertSame(4, DB::table('notification_states')->where('active', true)->count());
        $this->assertSame(0, $this->fakeConditions($conditions)->run()['queued'], 'cooldown still applies per condition');

        $this->assertSame(1, $this->fakeConditions([])->run()['queued']);
        $this->assertDatabaseHas('notification_queue', ['title' => '4 condições normalizadas']);
    }

    public function test_two_new_alerts_stay_individual(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure();
        $conditions = [
            'device:1' => ['label' => 'A', 'severity' => 'warning', 'reason' => 'x', 'detail' => ''],
            'device:2' => ['label' => 'B', 'severity' => 'warning', 'reason' => 'x', 'detail' => ''],
        ];
        $this->assertSame(2, $this->fakeConditions($conditions)->run()['queued']);
    }

    public function test_maintenance_window_defers_warnings_but_not_critical(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(['maintenance_enabled' => true, 'maintenance_start' => '00:00', 'maintenance_end' => '23:59']);
        $manager = $this->fakeConditions([
            'device:1' => ['label' => 'Backup com problema: A', 'severity' => 'warning', 'reason' => 'backup_delayed', 'detail' => 'x'],
            'system:engine_down' => ['label' => 'Motor parado', 'severity' => 'critical', 'reason' => 'engine_down', 'detail' => ''],
        ]);
        $this->assertSame(1, $manager->run()['queued']);
    }

    public function test_failed_delivery_retries_with_backoff_then_gives_up_without_leaking_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'secret detail'], 500)]);
        $this->configure();
        $manager = app(NotificationManager::class);
        $id = $manager->enqueue('test', null, 'info', 'Teste', 'x');

        for ($i = 1; $i <= 5; $i++) {
            $manager->deliver();
            $this->travel(20)->minutes();
        }
        $row = DB::table('notification_queue')->find($id);
        $this->assertSame('failed', $row->status);
        $this->assertSame(5, $row->attempts);
        $this->assertSame('TELEGRAM_HTTP_ERROR', $row->last_error);
    }

    public function test_html_in_titles_is_escaped(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure();
        $manager = app(NotificationManager::class);
        $manager->enqueue('alert', 'k', 'warning', '<script>x</script>', 'a & b');
        $manager->deliver();
        Http::assertSent(fn ($r) => str_contains($r['text'], '&lt;script&gt;') && str_contains($r['text'], 'a &amp; b'));
    }

    public function test_topic_id_is_sent_only_when_configured(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(['chat_id' => '-1001234567890', 'thread_id' => 1411]);
        $manager = app(NotificationManager::class);
        $manager->enqueue('test', null, 'info', 'Teste', '');
        $manager->deliver();
        Http::assertSent(fn ($r) => $r['message_thread_id'] === 1411 && $r['chat_id'] === '-1001234567890');

        $this->configure(['thread_id' => null]);
        $manager->enqueue('test', null, 'info', 'Teste', '');
        $manager->deliver();
        Http::assertSent(fn ($r) => ! isset($r['message_thread_id']));
    }

    public function test_daily_summary_is_sent_once_per_period_after_its_time(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(['daily_enabled' => true, 'daily_time' => '08:00']);
        $manager = app(NotificationManager::class);

        $this->travelTo(now('America/Sao_Paulo')->setTime(7, 0)->utc());
        $this->assertSame(0, $manager->summaries(), 'before the scheduled time');

        $this->travelTo(now('America/Sao_Paulo')->setTime(8, 5)->utc());
        $this->assertSame(1, $manager->summaries());
        $this->assertSame(0, $manager->summaries(), 'same period is never queued twice');
        $this->travel(2)->hours();
        $this->assertSame(0, $manager->summaries());

        $this->travel(1)->days();
        $this->assertSame(1, $manager->summaries(), 'next day, new period');
        $this->assertSame(2, DB::table('notification_queue')->where('kind', 'summary')->count());
    }

    public function test_weekly_summary_only_on_configured_weekday_and_counts_the_period(): void
    {
        $this->configure(['weekly_enabled' => true, 'weekly_day' => 2, 'weekly_time' => '08:00']); // quarta
        $manager = app(NotificationManager::class);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-07 09:00', 'America/Sao_Paulo')->utc()); // quarta
        $this->assertSame(1, $manager->summaries());
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-08 09:00', 'America/Sao_Paulo')->utc()); // quinta
        $this->assertSame(0, $manager->summaries());
    }

    public function test_summary_body_counts_period_events(): void
    {
        $summary = app(\App\Services\NotificationSummary::class);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-07 09:00', 'America/Sao_Paulo')->utc());
        [$title, $body, $key] = $summary->build('daily', app(\App\Services\InstanceTimezone::class)->localNow());
        $this->assertSame('Resumo diário', $title);
        $this->assertSame('summary:daily:2026-10-06', $key);
        $this->assertStringContainsString('Backups concluídos: 0', $body);
        $this->assertStringContainsString('Período: 06/10 00:00 a 07/10 00:00', $body);
    }

    public function test_summary_preview_is_queued_and_viewer_cannot(): void
    {
        $this->configure();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('settings.notifications.summary-test', 'weekly'))->assertRedirect();
        $this->assertDatabaseHas('notification_queue', ['kind' => 'test', 'severity' => 'summary']);
        $this->post('/settings/notifications/summary-test/monthly')->assertNotFound();

        $this->actingAs(User::factory()->viewer()->create());
        $this->post(route('settings.notifications.summary-test', 'daily'))->assertForbidden();
    }

    public function test_disabled_channel_still_delivers_test_message_only(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->configure(['enabled' => false]);
        $manager = app(NotificationManager::class);
        $manager->enqueue('alert', 'k', 'warning', 'Alerta', '');
        $manager->enqueue('test', null, 'info', 'Teste', '');
        $this->assertSame(1, $manager->deliver()['sent']);
    }
}
