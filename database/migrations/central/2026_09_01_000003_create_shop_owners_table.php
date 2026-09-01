<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->create('shop_owners', function (Blueprint $table) {
            $table->id();
            $table->uuid('shop_id')->unique();
            $table->string('name');
            $table->string('username');
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('shop_owners');
    }
};
