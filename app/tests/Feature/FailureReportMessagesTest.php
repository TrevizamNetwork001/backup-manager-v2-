<?php

namespace Tests\Feature;

use App\Models\BackupExecution;
use App\Models\BackupPolicy;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DeviceBackupPolicy;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** O relatório de falhas mostra a mensagem em português e deixa o código como detalhe. */
class FailureReportMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function failedExecution(DeviceBackupPolicy $association, string $code): void
    {
        $execution = BackupExecution::create(['device_backup_policy_id' => $association->id,
            'backup_policy_id' => $association->backup_policy_id, 'device_id' => $association->device_id,
            'credential_id' => $association->credential_id, 'origin' => 'manual',
            'status' => 'failed', 'attempt' => 1]);
        DB::table('backup_executions')->where('id', $execution->id)->update([
            'error_code' => $code, 'created_at' => CarbonImmutable::now('UTC')->subHour(),
        ]);
    }

    public function test_failure_page_shows_the_error_message_in_portuguese_with_the_code_as_detail(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'Switch A',
            'management_ip' => '192.0.2.10', 'vendor' => 'Huawei', 'is_active' => true]);
        $policy = BackupPolicy::create(['name' => 'Política A', 'method' => 'ssh_pull',
            'artifact_mode' => 'config', 'schedule_type' => 'manual', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH',
            'type' => 'ssh', 'username' => 'backup', 'is_active' => true]);
        $credential->secret = 'x';
        $credential->save();
        $association = DeviceBackupPolicy::create(['device_id' => $device->id, 'backup_policy_id' => $policy->id,
            'credential_id' => $credential->id, 'is_active' => true]);

        $this->failedExecution($association, 'SSH_AUTH_FAILED');
        $this->failedExecution($association, 'ENGINE_STALE');
        $this->failedExecution($association, 'CODIGO_NOVO_SEM_TRADUCAO');

        $this->actingAs(User::factory()->admin()->create())->get(route('reports.failures'))
            ->assertOk()
            ->assertSee('Autenticação SSH falhou.')
            ->assertSee('Execução interrompida: sinal de atividade expirado.')
            ->assertSee('Falha no processamento.')
            ->assertSee('<code>SSH_AUTH_FAILED</code>', false);
    }
}
