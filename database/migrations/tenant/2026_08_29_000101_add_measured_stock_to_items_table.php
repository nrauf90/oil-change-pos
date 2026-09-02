<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk consumables: oil bought by the carton and poured by the litre, AC gas
 * bought by the cylinder and charged by the kilogram.
 *
 * `stock_level` becomes decimal because half a litre of oil is a real amount.
 * Piece items keep whole numbers in the same column — one balance per item,
 * never two competing ideas of "how much is left".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('unit_of_measure')->default('piece')->after('type')->index();
            // How the shop buys it: a "Carton" of 4 bottles, 4 litres each.
            $table->string('pack_label')->nullable()->after('unit_of_measure');
            $table->unsignedInteger('units_per_pack')->nullable()->after('pack_label');
            $table->decimal('measure_per_unit', 10, 3)->nullable()->after('units_per_pack');
        });

        // SQLite cannot ALTER a column type in place, so rebuild both columns.
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('stock_level_new', 12, 3)->nullable();
            $table->decimal('low_stock_alert_new', 12, 3)->nullable();
        });

        DB::table('items')->update([
            'stock_level_new' => DB::raw('stock_level'),
            'low_stock_alert_new' => DB::raw('low_stock_alert'),
        ]);

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['stock_level', 'low_stock_alert']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->renameColumn('stock_level_new', 'stock_level');
            $table->renameColumn('low_stock_alert_new', 'low_stock_alert');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['unit_of_measure', 'pack_label', 'units_per_pack', 'measure_per_unit']);
        });
    }
};
