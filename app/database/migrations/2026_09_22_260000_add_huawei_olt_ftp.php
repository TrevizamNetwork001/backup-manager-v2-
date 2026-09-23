<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('platform', 20)->default('network');
        });
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->restrictOnDelete();
            $table->string('username', 32)->unique();
            $table->text('secret');
            $table->boolean('is_active')->default(true);
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
        Schema::table('device_backup_policies', fn (Blueprint $table) => $table->unsignedBigInteger('credential_id')->nullable()->change());
        Schema::table('backup_executions', fn (Blueprint $table) => $table->unsignedBigInteger('credential_id')->nullable()->change());
        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX backup_executions_one_running_device ON backup_executions (device_id) WHERE status = 'running'");
    }

    public function down(): void
    {
        // Historical FTP executions may have no SSH credential. Leave nullable on rollback.
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS backup_executions_one_running_device');
        Schema::dropIfExists('ftp_accounts');
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('platform'));
    }
};
