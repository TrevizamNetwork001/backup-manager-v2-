<?php

namespace Tests\Feature;

use App\Models\BackupArtifact;
use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\ArtifactStorage;
use App\Services\EngineJobService;
use App\Services\NotificationManager;
use App\Services\NotificationSettings;
use App\Services\TelegramBackupCopy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBackupCopyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    private string $root;

    private int $telegramStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/tg-backup-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        config()->set('backup.storage_root', $this->root);
        Http::fake(fn () => Http::response(['ok' => $this->telegramStatus < 300], $this->telegramStatus));
    }

    /** Campo de texto de uma requisição multipart (data() não os expõe por nome quando há arquivo). */
    private function field($request, string $name): mixed
    {
        foreach ($request->data() as $key => $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return $part['contents'];
            }
            if ($key === $name) {
                return $part;
            }
        }

        return null;
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function enable(array $extra = []): void
    {
        app(NotificationSettings::class)->save(array_merge(
            ['backup_copy_enabled' => true, 'backup_copy_chat_id' => '-100777', 'backup_copy_since' => now()->subDay()], $extra), self::TOKEN);
    }

    private function artifact(string $deviceName = 'SW Teste', ?CarbonImmutable $validatedAt = null): BackupArtifact
    {
        $site = Site::firstOrCreate(['name' => 'POP Teste'], ['is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => $deviceName,
            'management_ip' => '192.0.2.'.(Device::count() + 1), 'vendor' => 'MikroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Política '.uniqid(), 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'x';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);
        $job = BackupExecution::create(['device_backup_policy_id' => $association->id, 'backup_policy_id' => $policy->id,
            'device_id' => $device->id, 'credential_id' => $credential->id, 'origin' => 'manual', 'status' => 'succeeded', 'attempt' => 1]);
        $relative = app(EngineJobService::class)->relativePath($job);
        $path = $this->root.'/'.$relative;
        mkdir(dirname($path), 0700, true);
        $content = "/interface bridge\nadd name=bridge{$job->id}\n";
        file_put_contents($path, $content);

        return BackupArtifact::create(['backup_execution_id' => $job->id, 'device_id' => $device->id,
            'backup_policy_id' => $policy->id, 'type' => 'config', 'storage' => 'local', 'relative_path' => $relative,
            'original_filename' => basename($relative), 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'validated_at' => $validatedAt ?? CarbonImmutable::now('UTC')])->fresh();
    }

    public function test_only_backups_validated_after_enabling_are_queued_once(): void
    {
        $this->enable(['backup_copy_since' => now()->subHour()]);
        $this->artifact('Antigo', CarbonImmutable::now('UTC')->subDays(2));
        $this->artifact('Novo');
        $copy = app(TelegramBackupCopy::class);

        $this->assertSame(1, $copy->enqueueNew());
        $this->assertSame(0, $copy->enqueueNew(), 'não enfileira de novo');
        $this->assertSame(1, DB::table('telegram_backup_sends')->count());
    }

    public function test_nothing_happens_when_disabled_or_without_a_start_point(): void
    {
        $this->artifact();
        $this->assertSame(['queued' => 0, 'sent' => 0, 'failed' => 0], app(TelegramBackupCopy::class)->run());

        $this->enable(['backup_copy_since' => null]);
        $this->assertSame(0, app(TelegramBackupCopy::class)->enqueueNew(), 'sem data de início não envia o histórico');
        Http::assertNothingSent();
    }

    public function test_delivery_sends_a_zip_with_name_caption_and_topic(): void
    {
        $this->enable(['backup_copy_thread_id' => 1412]);
        $artifact = $this->artifact();

        $result = app(TelegramBackupCopy::class)->run();
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', DB::table('telegram_backup_sends')->value('status'));

        $expectedName = 'SW-Teste__'.$artifact->original_filename.'.zip';
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendDocument')
            && $request->hasFile('document', null, $expectedName)
            && $this->field($request, 'chat_id') === '-100777'
            && $this->field($request, 'message_thread_id') === '1412'
            && str_contains($this->field($request, 'caption'), 'Backup concluído')
            && str_contains($this->field($request, 'caption'), 'SW Teste')
            && str_contains($this->field($request, 'caption'), 'POP Teste')
            && str_contains($this->field($request, 'caption'), 'enviado em ZIP'));
    }

    public function test_text_files_are_zipped_and_compressed_ones_are_left_alone(): void
    {
        $artifact = $this->artifact();
        $path = app(ArtifactStorage::class)->verify($artifact)['path'];
        $prepare = new \ReflectionMethod(TelegramBackupCopy::class, 'prepare');

        [$zipPath, $zipName, $temporary] = $prepare->invoke(app(TelegramBackupCopy::class), $path, $artifact);
        $this->assertTrue($temporary);
        $this->assertStringEndsWith('.zip', $zipName);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath));
        $this->assertSame(file_get_contents($path), $zip->getFromName($artifact->original_filename));
        $zip->close();
        unlink($zipPath);

        $artifact->original_filename = 'SW.tar.gz';
        [$samePath, $sameName, $temporary] = $prepare->invoke(app(TelegramBackupCopy::class), $path, $artifact);
        $this->assertFalse($temporary);
        $this->assertSame($path, $samePath);
        $this->assertStringEndsWith('.tar.gz', $sameName);
    }

    public function test_failures_retry_then_give_up_and_a_later_success_clears_the_alert(): void
    {
        $this->enable();
        $this->artifact('Primeiro');
        $this->telegramStatus = 500;
        $copy = app(TelegramBackupCopy::class);
        $copy->enqueueNew();

        for ($i = 0; $i < 5; $i++) {
            $copy->deliver();
            $this->travel(20)->minutes();
        }
        $row = DB::table('telegram_backup_sends')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(5, $row->attempts);
        $this->assertSame('TELEGRAM_HTTP_ERROR', $row->error_code);
        $this->assertSame(1, $copy->failing()['count']);

        $this->telegramStatus = 200;
        $this->artifact('Segundo');
        $copy->run();
        $this->assertNull($copy->failing(), 'um envio bom depois da falha limpa o alerta');
    }

    public function test_invalid_token_fails_at_once_and_feeds_the_notification_condition(): void
    {
        $this->enable();
        $this->artifact();
        $this->telegramStatus = 401;
        $copy = app(TelegramBackupCopy::class);
        $copy->run();

        $row = DB::table('telegram_backup_sends')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('TELEGRAM_UNAUTHORIZED', $row->error_code);

        $this->assertSame('TELEGRAM_UNAUTHORIZED', $copy->failing()['error_code']);
    }

    public function test_failures_show_up_in_the_daily_summary_and_do_not_raise_an_alert(): void
    {
        $this->enable();
        $failedArtifact = $this->artifact('Equip Falhou');
        $sentArtifact = $this->artifact('Equip Enviado');
        $yesterday = CarbonImmutable::now('America/Sao_Paulo')->subDay()->setTime(12, 0)->utc();
        DB::table('telegram_backup_sends')->insert(['backup_artifact_id' => $failedArtifact->id, 'status' => 'failed',
            'error_code' => 'TELEGRAM_HTTP_ERROR', 'attempts' => 5, 'created_at' => $yesterday, 'updated_at' => $yesterday]);
        DB::table('telegram_backup_sends')->insert(['backup_artifact_id' => $sentArtifact->id, 'status' => 'sent',
            'sent_at' => $yesterday, 'attempts' => 1, 'created_at' => $yesterday, 'updated_at' => $yesterday]);

        [, $body] = app(\App\Services\NotificationSummary::class)
            ->build('daily', app(\App\Services\InstanceTimezone::class)->localNow());
        $this->assertStringContainsString('📨 Cópia no Telegram: 1 enviado(s), 1 com falha', $body);
        $this->assertStringContainsString('Equip Falhou — Resposta inesperada do Telegram.', $body);

        // sem alerta: a condição de saúde não existe mais para a cópia
        $this->assertFalse(method_exists(NotificationManager::class, 'telegramBackupConditions'));
    }

    public function test_file_over_the_limit_or_gone_from_disk_is_not_sent(): void
    {
        $this->enable();
        $big = $this->artifact('Grande');
        config()->set('backup.telegram_max_bytes', 1);
        $gone = $this->artifact('Sumiu');
        unlink(app(ArtifactStorage::class)->verify($gone)['path']);
        $copy = app(TelegramBackupCopy::class);
        $copy->enqueueNew();
        $copy->deliver();

        $this->assertSame('TELEGRAM_FILE_TOO_LARGE', DB::table('telegram_backup_sends')->where('backup_artifact_id', $big->id)->value('error_code'));
        $this->assertSame('ARTIFACT_UNAVAILABLE', DB::table('telegram_backup_sends')->where('backup_artifact_id', $gone->id)->value('error_code'));
        Http::assertNothingSent();
    }

    public function test_settings_screen_enable_requires_token_and_chat_and_viewer_cannot_change(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->put(route('settings.notifications.backup-copy.update'), ['backup_copy_enabled' => 1, 'backup_copy_chat_id' => '-100777'])
            ->assertSessionHasErrors('backup_copy_enabled'); // sem token do bot

        app(NotificationSettings::class)->save(['enabled' => false], self::TOKEN);
        $this->put(route('settings.notifications.backup-copy.update'), ['backup_copy_enabled' => 1])
            ->assertSessionHasErrors('backup_copy_enabled'); // sem chat id
        $this->put(route('settings.notifications.backup-copy.update'), ['backup_copy_enabled' => 1, 'backup_copy_chat_id' => 'abc'])
            ->assertSessionHasErrors('backup_copy_chat_id');

        $this->put(route('settings.notifications.backup-copy.update'), [
            'backup_copy_enabled' => 1, 'backup_copy_chat_id' => '-100777', 'backup_copy_thread_id' => 1412,
        ])->assertRedirect();
        $settings = app(NotificationSettings::class)->get();
        $this->assertTrue((bool) $settings->backup_copy_enabled);
        $this->assertSame('-100777', $settings->backup_copy_chat_id);
        $this->assertSame(1412, (int) $settings->backup_copy_thread_id);
        $this->assertNotNull($settings->backup_copy_since, 'ligar define o ponto de partida');
        $this->assertStringNotContainsString(self::TOKEN, DB::table('audit_events')->pluck('metadata')->implode(''));
        $this->get(route('settings.notifications.edit'))->assertOk()->assertSee('Cópia de backups no Telegram')->assertDontSee(self::TOKEN);

        $this->actingAs(User::factory()->viewer()->create());
        $this->put(route('settings.notifications.backup-copy.update'), ['backup_copy_enabled' => 0])->assertForbidden();
        $this->post(route('settings.notifications.backup-copy.test'))->assertForbidden();
    }

    public function test_test_button_reports_success_and_the_reason_for_failure(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post(route('settings.notifications.backup-copy.test'))->assertSessionHasErrors('backup_copy_enabled');

        $this->enable();
        $this->post(route('settings.notifications.backup-copy.test'))
            ->assertRedirect()->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->hasFile('document', null, 'arquivo-teste-backup-manager.txt') && $this->field($r, 'chat_id') === '-100777');

        $this->telegramStatus = 400;
        $this->post(route('settings.notifications.backup-copy.test'))
            ->assertRedirect()->assertSessionHas('warning', fn ($m) => str_contains($m, 'Destino recusado'));
    }
}
