<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('scope', 20);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_tokens');
    }
};
