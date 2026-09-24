<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->uuid('account_uuid')->nullable()->unique();
            $table->string('purpose', 20)->default('backup');
            $table->string('home_layout', 10)->default('legacy');
            $table->unsignedBigInteger('device_id')->nullable()->change();
        });
        Schema::table('ftp_account_audits', fn (Blueprint $table) => $table->unsignedBigInteger('device_id')->nullable()->change());
        foreach (DB::table('ftp_accounts')->select('id')->cursor() as $row) {
            DB::table('ftp_accounts')->where('id', $row->id)->update(['account_uuid' => (string) Str::uuid()]);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ftp_accounts ADD CONSTRAINT ftp_accounts_purpose_device_check CHECK ((purpose = 'backup' AND device_id IS NOT NULL) OR (purpose = 'file_server' AND device_id IS NULL))");
            DB::statement("ALTER TABLE ftp_accounts ADD CONSTRAINT ftp_accounts_home_layout_check CHECK (home_layout IN ('legacy', 'account'))");
            DB::statement("ALTER TABLE ftp_accounts ADD CONSTRAINT ftp_accounts_uuid_required_check CHECK (account_uuid IS NOT NULL)");
            DB::statement("ALTER TABLE ftp_accounts ADD CONSTRAINT ftp_accounts_legacy_device_check CHECK (home_layout <> 'legacy' OR device_id IS NOT NULL)");
        }
        Schema::create('ftp_received_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ftp_account_id')->constrained()->restrictOnDelete();
            $table->string('claim_token', 32)->unique();
            $table->string('original_filename', 255);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('status', 20);
            $table->string('relative_path', 255)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['ftp_account_id', 'received_at']);
            $table->index(['status', 'received_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE ftp_received_files ADD CONSTRAINT ftp_received_files_status_check CHECK (status IN ('processing', 'stored', 'quarantined'))");
        }
    }

    public function down(): void
    {
        if (DB::table('ftp_accounts')->where('home_layout', 'account')->exists() ||
            DB::table('ftp_received_files')->exists()) {
            throw new RuntimeException('Reversão bloqueada: existem contas ou recebimentos no modelo novo.');
        }
        Schema::dropIfExists('ftp_received_files');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ftp_accounts DROP CONSTRAINT IF EXISTS ftp_accounts_purpose_device_check');
            DB::statement('ALTER TABLE ftp_accounts DROP CONSTRAINT IF EXISTS ftp_accounts_home_layout_check');
            DB::statement('ALTER TABLE ftp_accounts DROP CONSTRAINT IF EXISTS ftp_accounts_uuid_required_check');
            DB::statement('ALTER TABLE ftp_accounts DROP CONSTRAINT IF EXISTS ftp_accounts_legacy_device_check');
        }
        Schema::table('ftp_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable(false)->change();
            $table->dropUnique(['account_uuid']);
            $table->dropColumn(['account_uuid', 'purpose', 'home_layout']);
        });
        Schema::table('ftp_account_audits', fn (Blueprint $table) => $table->unsignedBigInteger('device_id')->nullable(false)->change());
    }
};
