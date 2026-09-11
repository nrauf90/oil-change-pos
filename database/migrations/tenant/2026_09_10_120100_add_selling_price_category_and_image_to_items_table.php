<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('selling_price', 10, 2)->nullable()->after('unit_cost');
            $table->foreignId('category_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('image_path')->nullable()->after('is_active');
            $table->string('image_original_name')->nullable()->after('image_path');
            $table->string('image_mime_type', 100)->nullable()->after('image_original_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'selling_price', 'category_id', 'image_path', 'image_original_name', 'image_mime_type',
            ]);
        });
    }
};
