<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vela_mcp_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('idempotency_key')->unique();
            $table->string('capability');
            $table->string('target_external_id')->nullable();
            $table->json('payload');
            $table->json('before_snapshot')->nullable();
            $table->char('before_hash', 64);
            $table->json('after_snapshot')->nullable();
            $table->char('after_hash', 64)->nullable();
            $table->string('status')->default('prepared');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_mcp_proposals');
    }
};
