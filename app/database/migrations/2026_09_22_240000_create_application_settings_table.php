<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('timezone', 100);
            $table->timestamps();
        });

        DB::table('application_settings')->insert([
            'id' => 1, 'timezone' => 'America/Sao_Paulo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
