<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_page_blocks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('page_row_id');
            $table->tinyInteger('column_index')->default(0);
            $table->tinyInteger('column_width')->default(12);
            $table->integer('order_column')->default(0);
            $table->string('type');
            $table->longText('content')->nullable();
            $table->longText('settings')->nullable();
            $table->timestamps();

            $table->foreign('page_row_id')->references('id')->on('vela_page_rows')->onDelete('cascade');
            $table->index(['page_row_id', 'column_index', 'order_column']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_page_blocks');
    }
};
