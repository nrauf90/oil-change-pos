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
        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name')->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_plate', 40)->nullable()->unique();
            $table->unsignedInteger('mileage')->nullable();
            $table->timestamps();

            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
    }
};
