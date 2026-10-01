<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('retention_enabled')->default(false);
        });

        DB::table('application_settings')->where('id', 1)->update([
            'retention_enabled' => (bool) config('backup.retention_enabled'),
        ]);
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('retention_enabled');
        });
    }
};
