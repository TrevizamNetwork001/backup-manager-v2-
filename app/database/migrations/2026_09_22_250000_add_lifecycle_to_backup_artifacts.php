<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table) {
            $table->string('status', 20)->default('available');
            $table->timestamp('deleted_at')->nullable();
            $table->string('deletion_reason', 40)->nullable();
            $table->timestamp('missing_at')->nullable();
            $table->index(['status', 'backup_policy_id']);
        });
    }

    public function down(): void
    {
        Schema::table('backup_artifacts', function (Blueprint $table) {
            $table->dropIndex(['status', 'backup_policy_id']);
            $table->dropColumn(['status', 'deleted_at', 'deletion_reason', 'missing_at']);
        });
    }
};
