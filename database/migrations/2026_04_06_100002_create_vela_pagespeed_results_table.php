<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_pagespeed_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('url');
            $table->unsignedTinyInteger('performance_score')->nullable();
            $table->unsignedTinyInteger('accessibility_score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('best_practices_score')->nullable();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_pagespeed_results');
    }
};
