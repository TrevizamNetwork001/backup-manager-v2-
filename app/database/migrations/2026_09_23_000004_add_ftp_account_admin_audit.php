<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ftp_accounts', fn (Blueprint $table) => $table->timestamp('credential_changed_at')->nullable());
        Schema::create('ftp_account_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ftp_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->string('username', 32);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftp_account_audits');
        Schema::table('ftp_accounts', fn (Blueprint $table) => $table->dropColumn('credential_changed_at'));
    }
};
