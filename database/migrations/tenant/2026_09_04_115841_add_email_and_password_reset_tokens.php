<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds self-service password reset to the tenant schema.
 *
 * Every migration landing after the installation marker also runs against a
 * customer's existing production database during adoption, and adoption can be
 * interrupted and resumed, so this has to be re-runnable and has to refuse a
 * database whose shape would quietly break the feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('users', 'email')) {
            $email = collect($schema->getColumns('users'))->firstWhere('name', 'email');

            // A NOT NULL email would make every existing staff row unsaveable,
            // and staff legitimately have no address.
            if (! is_array($email) || ($email['nullable'] ?? null) !== true) {
                throw new RuntimeException(
                    'The users.email column is incompatible with the canonical tenant schema.',
                );
            }
        } else {
            $schema->table('users', function (Blueprint $table): void {
                // Nullable on purpose: staff sign in with a username, and most
                // workshop floor staff have no work email. An address is only
                // needed by someone resetting their own password — chiefly the
                // owner, who has nobody above them to ask.
                $table->string('email')->nullable()->after('name');
            });
        }

        // Two accounts sharing an address would make a reset link ambiguous
        // about who it belongs to. NULLs do not collide under this constraint.
        if (! $schema->hasIndex('users', ['email'])) {
            $schema->table('users', function (Blueprint $table): void {
                $table->unique('email');
            });
        }

        // Lives in the tenant database, not the central one: a token is only
        // meaningful against one shop's users, and two shops may legitimately
        // hold the same address.
        if (! $schema->hasTable('password_reset_tokens')) {
            $schema->create('password_reset_tokens', function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        $schema->dropIfExists('password_reset_tokens');

        if (! $schema->hasColumn('users', 'email')) {
            return;
        }

        $schema->table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });
    }
};
