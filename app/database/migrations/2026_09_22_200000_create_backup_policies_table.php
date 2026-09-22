<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('method', 20);
            $table->string('artifact_mode', 20);
            $table->string('schedule_type', 20);
            $table->time('schedule_time')->nullable();
            $table->unsignedTinyInteger('schedule_weekday')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->unsignedInteger('retention_count')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_policies');
    }
};
