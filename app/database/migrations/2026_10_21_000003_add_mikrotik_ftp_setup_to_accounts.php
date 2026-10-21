<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table): void {
            $table->string('mikrotik_setup_status', 20)->nullable();
            $table->timestamp('mikrotik_setup_started_at')->nullable();
            $table->timestamp('mikrotik_setup_completed_at')->nullable();
            $table->string('mikrotik_test_filename', 100)->nullable();
            $table->string('mikrotik_setup_error', 60)->nullable();
            $table->string('mikrotik_schedule_time', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table): void {
            $table->dropColumn([
                'mikrotik_setup_status', 'mikrotik_setup_started_at', 'mikrotik_setup_completed_at',
                'mikrotik_test_filename', 'mikrotik_setup_error', 'mikrotik_schedule_time',
            ]);
        });
    }
};
