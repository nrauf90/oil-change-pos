<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Staff accounts. Who can get into the till is as much a financial control as
 * the till itself, so adding, editing and removing an account are all on the
 * record.
 *
 * Nothing here ever touches the password. The plaintext is gone by the time the
 * model is saved, and the hash is deliberately not written either — an offline
 * cracking target has no business sitting in the one table the owner exports.
 * ActivityLog::record() scrubs it centrally as a second line of defence.
 */
class UserObserver
{
    public function created(User $user): void
    {
        ActivityLog::record(
            action: 'user.created',
            description: sprintf('Added staff account %s (@%s)', $user->name, $user->username),
            subject: $user,
            properties: [
                'name' => $user->name,
                'username' => $user->username,
                'is_active' => $user->is_active,
                'role' => $user->role()?->value,
            ],
        );
    }

    public function updated(User $user): void
    {
        $changed = ActivityLog::changes($user);

        // Recorded as a fact, never as a value: the shop needs to know a
        // credential was rotated and by whom, and needs to know nothing else.
        $passwordChanged = array_key_exists('password', $user->getChanges());

        // Signing in stamps last_login_at on every request through the login
        // screen. That is bookkeeping, not an administrative act, and it would
        // bury the entries that matter within a week.
        if ($changed === [] && ! $passwordChanged) {
            return;
        }

        $fields = array_keys($changed);

        if ($passwordChanged) {
            $fields[] = 'password';
        }

        ActivityLog::record(
            action: 'user.updated',
            description: sprintf('Edited staff account %s — changed %s', $user->name, implode(', ', $fields)),
            subject: $user,
            properties: [
                'username' => $user->username,
                'changed' => $changed,
                'password_changed' => $passwordChanged,
            ],
        );
    }

    public function deleted(User $user): void
    {
        ActivityLog::record(
            action: 'user.deleted',
            description: sprintf('Removed staff account %s (@%s)', $user->name, $user->username),
            subject: $user,
            properties: [
                'name' => $user->name,
                'username' => $user->username,
                'was_active' => $user->is_active,
            ],
        );
    }
}
