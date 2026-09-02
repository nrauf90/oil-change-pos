<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail.
 *
 * Deleting a sale erases a financial record; without this table there is no
 * way to answer "who removed invoice INV-… and what was on it?". Entries are
 * append-only: there is no `updated_at`, because an amended audit entry is
 * worth less than no audit entry at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();

            // Who. The FK nulls out when staff leave, so `user_name` carries a
            // snapshot of the name as it read at the moment of the action.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');

            // What. Machine key such as `sale.deleted`, indexed for filtering.
            $table->string('action')->index();

            // Which record it happened to, kept as loose columns: the row it
            // points at is normally gone by the time anyone reads the entry.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('description');

            // A snapshot of the values that were destroyed.
            $table->json('properties')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
