<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_backup_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->string('origin', 20);
            $table->string('status', 20);
            $table->unsignedInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 2000)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['origin', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_executions');
    }
};
