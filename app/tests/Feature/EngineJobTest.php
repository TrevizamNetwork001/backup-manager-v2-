<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use App\Services\EngineJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EngineJobTest extends TestCase
{
    use RefreshDatabase;

    private function queued(): BackupExecution
    {
        $site = Site::create(['name' => 'Laboratório', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.1', 'vendor' => 'MiKroTik', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Config', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH', 'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'senha-super-secreta';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id, 'credential_id' => $credential->id, 'is_active' => true]);
        $job = BackupExecution::createManual($association);
        $job->transitionTo('queued');
        return $job;
    }

    public function test_claim_is_single_use_and_terminal_job_cannot_be_reprocessed(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $this->assertSame($job->id, $engine->claim()->id);
        $this->assertNull($engine->claim());
        $engine->fail($job->id, 'SSH_TIMEOUT');
        $this->assertSame('failed', $job->fresh()->status);
        $engine->fail($job->id, 'SSH_AUTH_FAILED');
        $this->assertSame('SSH_TIMEOUT', $job->fresh()->error_code);
        $this->assertNull($engine->claim());
    }

    public function test_ssh_negotiation_error_uses_fixed_message(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $engine->fail($job->id, 'SSH_NEGOTIATION_FAILED');
        $this->assertSame('SSH_NEGOTIATION_FAILED', $job->fresh()->error_code);
        $this->assertSame('Negociação SSH incompatível.', $job->fresh()->error_message);
        $this->assertStringNotContainsString('senha-super-secreta', $job->fresh()->error_message);
    }

    public function test_host_key_errors_are_fixed_and_sanitized(): void
    {
        $first = $this->queued();
        $association = $first->association;
        foreach (['SSH_HOST_KEY_UNKNOWN', 'SSH_HOST_KEY_MISMATCH'] as $code) {
            $job = $code === 'SSH_HOST_KEY_UNKNOWN' ? $first : BackupExecution::createManual($association);
            if ($job->status === 'pending') $job->transitionTo('queued');
            $engine = app(EngineJobService::class);
            $engine->claim();
            $engine->fail($job->id, $code);
            $this->assertSame($code, $job->fresh()->error_code);
            $this->assertStringNotContainsString('senha-super-secreta', $job->fresh()->error_message);
        }
    }

    public function test_observation_requires_running_worker_and_never_trusts_automatically(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim(str_repeat('b', 32));
        $fingerprint = 'SHA256:'.str_repeat('A', 43);
        $engine->observeHostKey($job->id, str_repeat('b', 32), '192.0.2.1', 'ssh-rsa', $fingerprint);
        $device = $job->device->fresh();
        $this->assertSame($fingerprint, $device->ssh_observed_fingerprint);
        $this->assertSame('ssh-rsa', $device->ssh_observed_algorithm);
        $this->assertNull($device->ssh_host_key_fingerprint);
        $this->expectException(\RuntimeException::class);
        $engine->observeHostKey($job->id, str_repeat('c', 32), '192.0.2.1', 'ssh-rsa', $fingerprint);
    }

    public function test_trust_action_is_authenticated_and_copies_only_observation(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $fingerprint = 'SHA256:'.str_repeat('B', 43);
        $engine->observeHostKey($job->id, $job->fresh()->worker_id, '192.0.2.1', 'ssh-rsa', $fingerprint);
        $route = route('devices.ssh-host-key.trust', $job->device_id);
        $this->post($route, ['ssh_host_key_fingerprint' => 'SHA256:'.str_repeat('Z', 43)])
            ->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->post($route, ['ssh_host_key_fingerprint' => 'SHA256:'.str_repeat('Z', 43)])
            ->assertRedirect(route('devices.edit', $job->device_id));
        $device = $job->device->fresh();
        $this->assertSame($fingerprint, $device->ssh_host_key_fingerprint);
        $this->assertSame('ssh-rsa', $device->ssh_host_key_algorithm);
        $this->assertNotNull($device->ssh_host_key_trusted_at);
        $this->assertSame($user->id, $device->ssh_host_key_trusted_by);
        $this->get(route('devices.edit', $device))->assertOk()->assertSee($fingerprint)
            ->assertDontSee('senha-super-secreta')->assertDontSee('SHA256:'.str_repeat('Z', 43));
    }

    public function test_mismatch_observation_does_not_replace_trust(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $worker = $job->fresh()->worker_id;
        $engine->observeHostKey($job->id, $worker, '192.0.2.1', 'ssh-rsa', 'SHA256:'.str_repeat('C', 43));
        $this->actingAs(User::factory()->create())->post(route('devices.ssh-host-key.trust', $job->device_id));
        $trustedAt = $job->device->fresh()->ssh_host_key_trusted_at;
        $engine->observeHostKey($job->id, $worker, '192.0.2.1', 'ssh-ed25519', 'SHA256:'.str_repeat('D', 43));
        $device = $job->device->fresh();
        $this->assertSame('SHA256:'.str_repeat('C', 43), $device->ssh_host_key_fingerprint);
        $this->assertSame($trustedAt->toDateTimeString(), $device->ssh_host_key_trusted_at->toDateTimeString());
        $this->get(route('devices.edit', $device))->assertSee('Chave alterada')->assertDontSee('senha-super-secreta');
    }

    public function test_heartbeat_and_recovery_respect_threshold_and_terminal_state(): void
    {
        config()->set('backup.engine_stale_seconds', 300);
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim(str_repeat('d', 32));
        $this->assertTrue($engine->heartbeat($job->id, str_repeat('d', 32)));
        $this->assertFalse($engine->heartbeat($job->id, str_repeat('e', 32)));
        $this->assertSame(0, $engine->recoverStale());
        DB::table('backup_executions')->where('id', $job->id)->update(['heartbeat_at' => now()->subSeconds(301)]);
        $this->assertSame(1, $engine->recoverStale());
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('ENGINE_STALE', $job->fresh()->error_code);
        $this->assertSame('Execução interrompida: heartbeat expirado.', $job->fresh()->error_message);
        $this->assertNull($job->fresh()->worker_id);
        $this->assertFalse($engine->heartbeat($job->id, str_repeat('d', 32)));
        $this->assertSame(0, $engine->recoverStale());
    }

    public function test_artisan_bridge_claims_job_without_secret_in_stdout(): void
    {
        $job = $this->queued();
        $this->artisan('engine:claim', ['worker' => str_repeat('a', 32)])->expectsOutputToContain('"id":'.$job->id)->assertExitCode(0);
        $this->assertSame('running', $job->fresh()->status);
        $this->assertNotNull($job->fresh()->claimed_at);
        $this->assertNotNull($job->fresh()->heartbeat_at);
        $this->assertSame(str_repeat('a', 32), $job->fresh()->worker_id);
    }

    public function test_eligibility_and_secret_are_restricted(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $payload = $engine->job($job->id);
        $this->assertTrue($payload['eligible']);
        $this->assertSame('MiKroTik', $payload['vendor']);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertSame('senha-super-secreta', $engine->secret($job->id));
        $job->credential->update(['is_active' => false]);
        $this->assertFalse($engine->job($job->id)['eligible']);
        $this->expectException(\RuntimeException::class);
        $engine->secret($job->id);
    }

    public function test_artifact_validates_content_hash_path_and_history(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $root = sys_get_temp_dir().'/backup-test-'.$job->id.'-'.bin2hex(random_bytes(4));
        mkdir($root);
        config()->set('backup.storage_root', $root);
        $relative = $engine->relativePath($job);
        mkdir(dirname($root.'/'.$relative), 0700, true);
        $contents = "# RouterOS 7\n/interface bridge\nadd name=bridge1\n";
        file_put_contents($root.'/'.$relative, $contents);
        try {
            $artifact = $engine->complete($job->id, $relative);
            $this->assertSame($job->id, $artifact->backup_execution_id);
            $this->assertSame($job->device_id, $artifact->device_id);
            $this->assertSame(strlen($contents), $artifact->size_bytes);
            $this->assertSame(hash('sha256', $contents), $artifact->sha256);
            $this->assertSame('succeeded', $job->fresh()->status);
            $this->assertDatabaseHas('backup_artifacts', ['id' => $artifact->id]);
            try { $engine->complete($job->id, $relative); $this->fail('Execução concluída foi reprocessada.'); }
            catch (ValidationException) { $this->assertDatabaseCount('backup_artifacts', 1); }
            try { DB::table('backup_executions')->where('id', $job->id)->delete(); $this->fail('Histórico apagado.'); }
            catch (\Illuminate\Database\QueryException) { $this->assertDatabaseHas('backup_artifacts', ['id' => $artifact->id]); }
            $this->actingAs(User::factory()->create());
            $this->get(route('backup-artifacts.index'))->assertOk()->assertDontSee('bridge1')
                ->assertDontSee('senha-super-secreta');
        } finally {
            unlink($root.'/'.$relative);
            rmdir(dirname($root.'/'.$relative));
            rmdir(dirname(dirname($root.'/'.$relative)));
            rmdir(dirname(dirname(dirname($root.'/'.$relative))));
            rmdir(dirname(dirname(dirname(dirname($root.'/'.$relative)))));
            rmdir($root);
        }
    }

    public function test_invalid_files_and_traversal_are_rejected(): void
    {
        $job = $this->queued();
        $engine = app(EngineJobService::class);
        $engine->claim();
        $root = sys_get_temp_dir().'/backup-test-'.$job->id.'-'.bin2hex(random_bytes(4));
        mkdir($root);
        config()->set('backup.storage_root', $root);
        $relative = $engine->relativePath($job);
        mkdir(dirname($root.'/'.$relative), 0700, true);
        try {
            foreach (['', str_repeat('x', 8 * 1024 * 1024 + 1), 'error: denied'] as $contents) {
                file_put_contents($root.'/'.$relative, $contents);
                try { $engine->complete($job->id, $relative); $this->fail('Arquivo inválido aceito.'); }
                catch (ValidationException) { $this->assertSame('running', $job->fresh()->status); }
            }
            $this->expectException(\RuntimeException::class);
            $engine->resolvePath('../outside.rsc');
        } finally {
            unlink($root.'/'.$relative);
            rmdir(dirname($root.'/'.$relative));
            rmdir(dirname(dirname($root.'/'.$relative)));
            rmdir(dirname(dirname(dirname($root.'/'.$relative))));
            rmdir(dirname(dirname(dirname(dirname($root.'/'.$relative)))));
            rmdir($root);
        }
    }
}
