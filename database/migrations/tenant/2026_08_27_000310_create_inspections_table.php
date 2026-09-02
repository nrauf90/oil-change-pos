<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-point inspections — the workshop floor's condition report.
 *
 * Deliberately carries no money column of any kind: an inspection tells the
 * customer what the car needs, never what it will cost. Pricing is the
 * counter's job, on the sale screen, typed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->id();

            // Optional: an inspection often happens during a visit, but a
            // technician may also log one for a car that is only being looked at.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            // Who did the work. Set from the session, never from the request
            // body. Nulled rather than cascaded so removing a member of staff
            // never deletes the reports they wrote.
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('vehicle_plate', 40)->index();
            $table->string('vehicle_model')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('inspected_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
