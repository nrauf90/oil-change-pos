<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §2 — optional stock tracking for Products.
 *
 * Both columns are nullable and default to null: a null `stock_level` means
 * "this item is not stock-tracked", which is the only sensible default for a
 * Repair (you cannot keep four wheel alignments on a shelf) and also for the
 * bulk consumables a shop never counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->integer('stock_level')->nullable()->after('unit_cost');
            $table->integer('low_stock_alert')->nullable()->after('stock_level');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['stock_level', 'low_stock_alert']);
        });
    }
};
