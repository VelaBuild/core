<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_ai_action_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->string('tool_name');
            $table->json('parameters');
            $table->json('previous_state')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->foreign('conversation_id')->references('id')->on('vela_ai_conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('vela_ai_messages')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('vela_users')->cascadeOnDelete();
            $table->index('conversation_id');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_ai_action_logs');
    }
};
