<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_menus', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Slot key the theme references in @velaMenu('primary'). One row per slot.
            $table->string('slot', 64)->unique();
            $table->string('label')->nullable();
            $table->boolean('auto_add_pages')->default(false);
            $table->timestamps();
        });

        Schema::create('vela_menu_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('menu_id');
            $table->integer('order_column')->default(0);
            // page | content | category | url | route | home | posts_index | categories_index
            $table->string('type', 32);
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('label')->nullable();
            $table->string('url', 1000)->nullable();
            $table->string('route_name')->nullable();
            $table->string('target', 16)->default('_self');
            $table->timestamps();

            $table->foreign('menu_id')->references('id')->on('vela_menus')->onDelete('cascade');
            $table->index(['menu_id', 'order_column']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_menu_items');
        Schema::dropIfExists('vela_menus');
    }
};
