<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('resource_type', 100);
            $table->string('resource_id', 100)->nullable();
            $table->string('resource_label', 255)->nullable();
            $table->string('result', 30);
            $table->ipAddress('ip_address')->nullable();
            $table->json('metadata');
            $table->timestamp('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['actor_user_id', 'created_at']);
        });
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->string('deletion_mode', 20)->nullable();
            $table->string('deletion_error', 255)->nullable();
            $table->foreignId('deletion_actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('deletion_ip')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
        });
        Schema::table('ftp_account_audits', function (Blueprint $table) {
            $table->dropForeign(['ftp_account_id']);
            $table->unsignedBigInteger('ftp_account_id')->nullable()->change();
            $table->foreign('ftp_account_id')->references('id')->on('ftp_accounts')->nullOnDelete();
        });
        Schema::table('ftp_received_files', function (Blueprint $table) {
            $table->dropForeign(['ftp_account_id']);
            $table->unsignedBigInteger('ftp_account_id')->nullable()->change();
            $table->foreign('ftp_account_id')->references('id')->on('ftp_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('ftp_accounts')->whereNotNull('deletion_mode')->exists()) {
            throw new RuntimeException('Há exclusões FTP pendentes.');
        }
        Schema::table('ftp_account_audits', function (Blueprint $table) {
            $table->dropForeign(['ftp_account_id']);
            $table->foreign('ftp_account_id')->references('id')->on('ftp_accounts')->restrictOnDelete();
        });
        if (\Illuminate\Support\Facades\DB::table('ftp_received_files')->whereNull('ftp_account_id')->exists()) {
            throw new RuntimeException('Há recebimentos históricos desvinculados.');
        }
        Schema::table('ftp_received_files', function (Blueprint $table) {
            $table->dropForeign(['ftp_account_id']);
            $table->unsignedBigInteger('ftp_account_id')->nullable(false)->change();
            $table->foreign('ftp_account_id')->references('id')->on('ftp_accounts')->restrictOnDelete();
        });
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deletion_actor_id');
            $table->dropColumn(['deletion_mode', 'deletion_error', 'deletion_ip', 'deletion_requested_at']);
        });
        Schema::dropIfExists('audit_events');
    }
};
