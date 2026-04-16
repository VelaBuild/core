<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('comment');
            $table->string('status');
            $table->string('useragent')->nullable();
            $table->string('ipaddress')->nullable();
            $table->integer('parent')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('vela_users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_comments');
    }
};
