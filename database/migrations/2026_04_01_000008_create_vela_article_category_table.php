<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_article_category', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('article_id');
            $table->unsignedBigInteger('category_id');
            $table->timestamps();

            $table->foreign('article_id')->references('id')->on('vela_articles')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('vela_categories')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_article_category');
    }
};
