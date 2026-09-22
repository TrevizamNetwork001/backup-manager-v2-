<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_backup_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->foreignId('credential_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['device_id', 'backup_policy_id']);
            $table->index('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_backup_policies');
    }
};
