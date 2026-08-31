<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trail now records ordinary work — every sale, every edit — not just
 * deletions, so it grows by hundreds of rows a week rather than a handful.
 *
 * The screen's two structural filters are almost always used together ("item
 * edits, last month"), and the single-column indexes already on `action` and
 * `created_at` let SQLite use only one of them. This composite covers the pair,
 * and covers a bare `action` filter as its leading column, so it does the work
 * of both. The free-text search over `description` is deliberately left
 * unindexed: a leading-wildcard LIKE cannot use a b-tree, and a full-text index
 * is not worth its write cost on a single-shop database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['action', 'created_at']);
        });
    }
};
