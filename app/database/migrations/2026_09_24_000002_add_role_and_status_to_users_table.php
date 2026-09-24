<?php

use App\Support\Rbac;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default(Rbac::ROLE_VIEWER)->after('is_admin');
            $table->boolean('is_active')->default(true)->after('role');
            $table->index('role');
        });

        // Backfill: preserve current admins, keep everyone else on the safest role.
        // is_admin stays in the schema for compatibility but is no longer read by
        // the authorization layer — role/permissions are now the source of truth.
        DB::table('users')->where('is_admin', true)->update(['role' => Rbac::ROLE_ADMIN]);
        DB::table('users')->where('is_admin', false)->update(['role' => Rbac::ROLE_VIEWER]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
