<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('operator')->change();
        });

        DB::transaction(function () {
            $legacyUsers = DB::table('users')->whereIn('role', ['viewer', 'auditor'])
                ->get(['id', 'name', 'role']);

            foreach ($legacyUsers as $user) {
                DB::table('users')->where('id', $user->id)->update([
                    'role' => 'operator',
                    'remember_token' => null,
                    'updated_at' => now(),
                ]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('audit_events')->insert([
                    'actor_user_id' => null,
                    'action' => 'user.role_changed',
                    'resource_type' => 'user',
                    'resource_id' => (string) $user->id,
                    'resource_label' => $user->name,
                    'result' => 'success',
                    'ip_address' => null,
                    'metadata' => json_encode([
                        'target_user_id' => $user->id,
                        'old_role' => $user->role,
                        'new_role' => 'operator',
                        'reason' => 'role_unification',
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('A unificação dos perfis requer restauração manual dos perfis anteriores.');
    }
};
