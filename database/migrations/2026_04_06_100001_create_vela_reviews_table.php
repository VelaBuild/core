<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source');                    // 'google' or 'manual'
            $table->string('place_id')->nullable();       // Google Place ID
            $table->string('external_id')->nullable();    // Google review ID for dedup
            $table->string('author');
            $table->unsignedTinyInteger('rating');         // 1-5
            $table->text('text')->nullable();
            $table->datetime('review_date')->nullable();
            $table->datetime('synced_at')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('source');
            $table->index('published');
            $table->unique('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_reviews');
    }
};
