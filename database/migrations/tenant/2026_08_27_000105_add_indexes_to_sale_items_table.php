<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite does not create an index behind a foreign key, so every
 * `withCount('lines')` on the sales list and every dashboard rollup was a full
 * table scan of sale_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->index('sale_id');
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['item_id']);
        });
    }
};
