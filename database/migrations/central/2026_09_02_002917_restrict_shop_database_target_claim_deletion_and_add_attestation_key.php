<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('central')->table('shops', function (Blueprint $table) {
            $table->text('database_attestation_key')->nullable()->after('database_password');
        });

        DB::connection('central')->table('shops')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $shop): void {
                DB::connection('central')->table('shops')->where('id', $shop->id)->update([
                    'database_attestation_key' => Crypt::encryptString(base64_encode(random_bytes(32))),
                ]);
            }, column: 'id');

        Schema::connection('central')->table('shop_database_target_claims', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
        });
        Schema::connection('central')->table('shop_database_target_claims', function (Blueprint $table) {
            $table->foreign('shop_id')->references('id')->on('shops')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->table('shop_database_target_claims', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
        });
        Schema::connection('central')->table('shop_database_target_claims', function (Blueprint $table) {
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
        });

        Schema::connection('central')->table('shops', function (Blueprint $table) {
            $table->dropColumn('database_attestation_key');
        });
    }
};
