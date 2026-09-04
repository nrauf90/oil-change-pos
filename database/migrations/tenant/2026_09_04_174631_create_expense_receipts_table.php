<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            // Proof dies with the outlay it proves: deleting an expense takes
            // its receipts with it, and the observer clears the files.
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            // Who attached it. Nullable + nullOnDelete so removing a member of
            // staff never rewrites the paper trail they filed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Tenant-scoped path on the private 'local' disk, never a URL.
            $table->string('path');
            // Kept so a download reaches the counter under the name they know.
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_in_bytes');
            $table->timestamps();

            // The expense view always asks "every receipt for this outlay, oldest first".
            $table->index(['expense_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
