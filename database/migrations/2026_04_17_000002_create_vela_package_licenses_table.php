<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_package_licenses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('installed_package_id');
            $table->text('license_key');
            $table->string('domain');
            $table->string('dev_domain')->nullable();
            $table->enum('type', ['free', 'onetime', 'yearly']);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->enum('validation_status', ['valid', 'expired', 'invalid', 'pending'])->default('pending');
            $table->string('marketplace_purchase_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('installed_package_id')
                  ->references('id')
                  ->on('vela_installed_packages')
                  ->onDelete('cascade');

            $table->index('domain');
            $table->index('validation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_package_licenses');
    }
};
