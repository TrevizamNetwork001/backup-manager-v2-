<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('backup_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_execution_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('backup_policy_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('storage', 20);
            $table->string('relative_path', 500)->unique();
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('validated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_artifacts');
    }
};
