<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a bulk consumable this line actually drew — 2.5 litres of oil,
 * 1.5 kg of AC gas.
 *
 * This is a stock record, not a billing line. It never reaches the customer's
 * invoice; `manually_charged_price` remains the only figure that bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('dispensed_quantity', 12, 3)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('dispensed_quantity');
        });
    }
};
