<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Expense;

class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        ActivityLog::record(
            action: 'expense.created',
            description: sprintf(
                'Logged a %s expense of %s',
                $expense->category?->label() ?? 'uncategorised',
                number_format((float) $expense->amount, 2),
            ),
            subject: $expense,
            properties: [
                'category' => $expense->category?->value,
                'amount' => $expense->amount,
                'description' => $expense->description,
                'spent_at' => $expense->spent_at?->toDateTimeString(),
                'logged_by_id' => $expense->user_id,
            ],
        );
    }

    public function updated(Expense $expense): void
    {
        $changed = ActivityLog::changes($expense);

        if ($changed === []) {
            return;
        }

        ActivityLog::record(
            action: 'expense.updated',
            description: sprintf(
                'Amended a %s expense (%s)',
                $expense->category?->label() ?? 'uncategorised',
                implode(', ', array_keys($changed)),
            ),
            subject: $expense,
            properties: [
                'category' => $expense->category?->value,
                'amount' => $expense->amount,
                'changed' => $changed,
            ],
        );
    }

    public function deleted(Expense $expense): void
    {
        ActivityLog::record(
            action: 'expense.deleted',
            description: sprintf(
                'Deleted %s expense of %s',
                $expense->category?->label() ?? 'uncategorised',
                number_format((float) $expense->amount, 2),
            ),
            subject: $expense,
            properties: [
                'category' => $expense->category?->value,
                'amount' => $expense->amount,
                'description' => $expense->description,
                'spent_at' => $expense->spent_at?->toDateTimeString(),
                'logged_by_id' => $expense->user_id,
            ],
        );
    }
}
