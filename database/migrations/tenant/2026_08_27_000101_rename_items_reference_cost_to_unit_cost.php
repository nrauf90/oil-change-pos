<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §5 names this column `unit_cost`. Renaming (not dropping and re-adding)
 * keeps every cost already typed into the master inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->renameColumn('reference_cost', 'unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->renameColumn('unit_cost', 'reference_cost');
        });
    }
};
