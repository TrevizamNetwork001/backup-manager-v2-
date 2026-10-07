<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->boolean('daily_enabled')->default(false);
            $table->string('daily_time', 5)->default('08:00');
            $table->boolean('weekly_enabled')->default(false);
            $table->unsignedTinyInteger('weekly_day')->default(0); // 0 = segunda … 6 = domingo
            $table->string('weekly_time', 5)->default('08:00');
        });
    }

    public function down(): void
    {
        Schema::table('notification_settings', function (Blueprint $table) {
            $table->dropColumn(['daily_enabled', 'daily_time', 'weekly_enabled', 'weekly_day', 'weekly_time']);
        });
    }
};
