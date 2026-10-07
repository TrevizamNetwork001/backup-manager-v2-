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
