<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_ai_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('role'); // user, assistant, system, tool
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->timestamps();
            $table->foreign('conversation_id')->references('id')->on('vela_ai_conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_ai_messages');
    }
};
