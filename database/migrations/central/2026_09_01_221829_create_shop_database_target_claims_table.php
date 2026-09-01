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
        Schema::connection('central')->create('shop_database_target_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('fingerprint', 64)
                ->unique('shop_database_target_claims_fingerprint_unique');
            $table->index('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('shop_database_target_claims');
    }
};
