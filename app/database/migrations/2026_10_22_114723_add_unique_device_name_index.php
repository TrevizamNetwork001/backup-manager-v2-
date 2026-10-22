<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX devices_name_normalized_unique ON devices (lower(trim(name)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS devices_name_normalized_unique');
    }
};
