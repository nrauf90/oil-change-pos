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
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('supplier_payment_id')->nullable()->unique()
                ->after('user_id')->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['supplier_payment_id']);
            $table->dropColumn(['supplier_payment_id', 'payment_method']);
        });
    }
};
