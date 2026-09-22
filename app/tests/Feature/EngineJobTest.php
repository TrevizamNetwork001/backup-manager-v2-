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

    public function test_artisan_bridge_claims_job_without_secret_in_stdout(): void
    {
        $job = $this->queued();
        $this->artisan('engine:claim')->expectsOutputToContain('"id":'.$job->id)->assertExitCode(0);
        $this->assertSame('running', $job->fresh()->status);
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
