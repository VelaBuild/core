<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_translations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('lang_code');
            $table->string('model_type');
            $table->string('model_key');
            $table->longText('translation')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_translations');
    }
};
