<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->text('bot_token')->nullable();
            $table->string('chat_id', 100)->nullable();
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);
            $table->boolean('maintenance_enabled')->default(false);
            $table->string('maintenance_start', 5)->default('22:00');
            $table->string('maintenance_end', 5)->default('06:00');
            $table->timestamps();
        });
        DB::table('notification_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('notification_queue', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // alert | recovery | test
            $table->string('condition_key', 150)->nullable();
            $table->string('severity', 10);
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 60)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('created_at');
        });

        Schema::create('notification_states', function (Blueprint $table) {
            $table->string('condition_key', 150)->primary();
            $table->boolean('active')->default(true);
            $table->string('reason', 100)->nullable();
            $table->string('label', 200);
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_states');
        Schema::dropIfExists('notification_queue');
        Schema::dropIfExists('notification_settings');
    }
};
