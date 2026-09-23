<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->string('ftp_host', 255)->nullable();
            $table->string('ftp_passive_address', 255)->nullable();
            $table->unsignedSmallInteger('ftp_port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn(['ftp_host', 'ftp_passive_address', 'ftp_port']);
        });
    }
};
