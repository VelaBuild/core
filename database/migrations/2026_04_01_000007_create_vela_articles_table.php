<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_articles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->string('slug')->nullable();
            $table->string('type')->default('post');
            $table->string('keyword')->nullable();
            $table->longText('description')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('status')->nullable();
            $table->datetime('written_at')->nullable();
            $table->datetime('approved_at')->nullable();
            $table->datetime('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('author_id')->references('id')->on('vela_users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_articles');
    }
};
