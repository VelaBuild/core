<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vela_installed_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('vendor_name');
            $table->string('package_name');
            $table->string('composer_name')->unique();
            $table->string('version', 50);
            $table->enum('status', ['active', 'disabled', 'expired', 'suspended'])->default('active');
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vela_installed_packages');
    }
};
