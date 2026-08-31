<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            // Nullable: pure-custom lines have no inventory item, and deleting an
            // item must never rewrite an invoice that was already printed.
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');           // snapshot at time of sale
            $table->string('type')->index();
            $table->decimal('manually_charged_price', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
