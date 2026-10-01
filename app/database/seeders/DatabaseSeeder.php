<?php

namespace Database\Seeders;

use App\Models\BackupPolicy;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (BackupPolicy::query()->exists()) {
            return;
        }

        $now = now();
        BackupPolicy::query()->insert([
            ['name' => 'MikroTik Diário', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'retention_days' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Huawei Switch SSH', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'retention_days' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Huawei Roteador SSH', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'daily', 'schedule_time' => '03:00', 'retention_days' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Huawei FTP', 'method' => 'ftp_push', 'artifact_mode' => 'config', 'schedule_type' => 'manual', 'schedule_time' => null, 'retention_days' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'SSH OLT-VSOL', 'method' => 'ssh_pull', 'artifact_mode' => 'config', 'schedule_type' => 'daily', 'schedule_time' => '02:00', 'retention_days' => 30, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
