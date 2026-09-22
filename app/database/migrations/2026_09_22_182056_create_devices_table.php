<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('management_ip', 45);
            $table->string('vendor', 100);
            $table->string('model')->nullable();
            $table->string('os_version')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique('management_ip');
            $table->index(['site_id', 'is_active']);
            $table->index('vendor');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
