<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One line on a bill still being built.
 *
 * Mirrors `sale_items` so completing an order is a copy rather than a
 * translation. The one difference is that nothing here is a financial record
 * yet: a draft line can be edited or removed freely, and no stock has moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Nullable so a custom, free-text line is possible, and so deleting
            // a catalogue item never destroys the bill that referenced it.
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('item_name');
            $table->string('type')->index();
            $table->unsignedInteger('quantity')->default(1);
            // How much bulk stock this line will draw (2.5 L of oil). A record
            // only — it never reaches the printed invoice.
            $table->decimal('dispensed_quantity', 12, 3)->nullable();
            $table->decimal('manually_charged_price', 12, 2)->default(0);

            $table->timestamps();

            // Reopening a draft always asks "every line for this order, in order".
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
