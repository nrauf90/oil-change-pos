<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill the counter is still building.
 *
 * The workshop runs several bays at once, so several bills have to be open at
 * once. An order is the mutable thing in front of the sale: it can be saved
 * half-finished, reopened, and added to. Completing it writes a Sale, and the
 * Sale stays what it has always been — an immutable financial record.
 *
 * Statuses live here and never on `sales`, so no report ever has to ask whether
 * a sale row was finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Who started it. Nullable + nullOnDelete so removing a member of
            // staff never erases the bill they were building.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Set once, when the order is completed. Its presence is what makes
            // an order finished, so an invoice can never be reached before it.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status')->index();
            // What the counter calls this bay — "Bay 2", a plate, a name. Falls
            // back to the plate, then the customer, then the time it was opened.
            $table->string('label')->nullable();

            $table->string('customer_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_plate', 40)->nullable()->index();
            $table->unsignedInteger('mileage')->nullable();
            $table->unsignedInteger('next_checkup_mileage')->nullable();

            $table->decimal('labor_charge', 12, 2)->default(0);
            $table->decimal('misc_charge', 12, 2)->default(0);
            $table->text('notes')->nullable();

            // Bumped on every save. Two counter staff with the same draft open
            // would otherwise silently overwrite each other.
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            // The draft list always asks "what is still open, oldest first".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
