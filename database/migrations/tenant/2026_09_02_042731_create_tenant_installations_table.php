<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_installations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('shop_id')->unique();
            $table->char('target_fingerprint', 64);
            $table->char('attestation_hmac', 64);
            $table->char('connection_nonce', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_installations');
    }
};
