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
        Schema::connection('central')->create('shop_features', function (Blueprint $table) {
            $table->id();
            $table->uuid('shop_id');
            $table->string('module_key');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique(['shop_id', 'module_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('shop_features');
    }
};
