<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            // Who logged it. Nullable + nullOnDelete so removing a member of
            // staff never rewrites the cash history they recorded.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->index();
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            // When the money actually left the drawer — back-datable, and the
            // only column the cash-drawer reconciliation ever filters on.
            $table->dateTime('spent_at')->index();
            $table->timestamps();

            // The cash drawer always asks "this window, by category".
            $table->index(['spent_at', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
