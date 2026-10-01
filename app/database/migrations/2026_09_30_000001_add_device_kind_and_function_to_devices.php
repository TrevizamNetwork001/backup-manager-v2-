<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('device_kind', 20)->nullable()->after('platform');
            $table->string('device_function', 100)->nullable()->after('device_kind');
        });

        DB::table('devices')->where('platform', 'olt')->update(['device_kind' => 'olt']);
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['device_kind', 'device_function']);
        });
    }
};
