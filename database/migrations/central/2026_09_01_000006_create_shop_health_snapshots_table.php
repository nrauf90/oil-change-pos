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
        Schema::connection('central')->create('shop_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('shop_id')->unique();
            $table->timestamp('last_successful_connection_at')->nullable();
            $table->string('migration_status')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('shop_health_snapshots');
    }
};
