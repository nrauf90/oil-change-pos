<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §5 — Sale_Items.quantity.
 *
 * Quantity is bookkeeping and print detail: how many units left the shelf, and
 * what the invoice prints as "x2". It is deliberately NOT a multiplier —
 * `manually_charged_price` stays the total charged for the line, typed by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
