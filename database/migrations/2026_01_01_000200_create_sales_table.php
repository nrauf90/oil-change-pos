<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_plate', 40)->nullable()->index();
            $table->unsignedInteger('mileage')->nullable();
            // Every money column below is exactly what the counter typed.
            $table->decimal('labor_charge', 12, 2)->default(0);
            $table->decimal('misc_charge', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
