<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One check-point of one inspection: what was looked at, the verdict, and an
 * optional note. No price, ever — see the inspections table comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();

            // App\Enums\InspectionPoint value, e.g. 'brake_pads'.
            $table->string('point', 40);

            // App\Enums\InspectionStatus value: ok | attention | urgent.
            $table->string('status', 20)->index();

            $table->string('note', 500)->nullable();

            // Keeps a stored report in the shop's fixed procedure order.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            // A check-point is inspected once per report.
            $table->unique(['inspection_id', 'point']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_items');
    }
};
