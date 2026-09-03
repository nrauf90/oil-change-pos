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
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('roles', 'description')) {
            $description = collect($schema->getColumns('roles'))->firstWhere('name', 'description');

            if (! is_array($description)
                || ($description['type_name'] ?? null) !== 'text'
                || ($description['nullable'] ?? null) !== true) {
                throw new RuntimeException(
                    'The roles.description column is incompatible with the canonical tenant schema.',
                );
            }

            return;
        }

        $schema->table('roles', function (Blueprint $table): void {
            $table->text('description')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasColumn('roles', 'description')) {
            return;
        }

        $schema->table('roles', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
