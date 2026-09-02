<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §5 — Sales.cashier_id.
 *
 * Nullable and nullOnDelete: removing a member of staff must never delete the
 * shop's takings, and older invoices predate the column. Attribution is a note
 * on the sale, not a dependency of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('cashier_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cashier_id');
        });
    }
};
