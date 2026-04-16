<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_page_rows', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('page_id');
            $table->string('name')->nullable();
            $table->string('css_class')->nullable();
            $table->integer('order_column')->default(0);
            $table->timestamps();

            $table->foreign('page_id')->references('id')->on('vela_pages')->onDelete('cascade');
            $table->index(['page_id', 'order_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_page_rows');
    }
};
