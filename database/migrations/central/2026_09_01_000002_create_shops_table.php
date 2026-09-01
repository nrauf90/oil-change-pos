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
        Schema::connection('central')->create('shops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->index();
            $table->string('database_driver');
            $table->string('database_name');
            $table->string('database_target_fingerprint', 64)
                ->unique('shops_database_target_fingerprint_unique');
            $table->text('database_host')->nullable();
            $table->text('database_port')->nullable();
            $table->text('database_socket')->nullable();
            $table->text('database_username')->nullable();
            $table->text('database_password')->nullable();
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('currency', 3)->default('PKR');
            $table->timestamp('provisioning_failed_at')->nullable();
            $table->text('provisioning_failure_message')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('central')->dropIfExists('shops');
    }
};
