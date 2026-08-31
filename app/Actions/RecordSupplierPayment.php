<?php

namespace App\Actions;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSupplierPayment
{
    /** @param array<string, mixed> $data */
    public function __invoke(Supply $supply, User $user, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($supply, $user, $data): SupplierPayment {
            $lockedSupply = Supply::query()->lockForUpdate()->findOrFail($supply->id);
            $paidCents = $lockedSupply->payments()->pluck('amount')
                ->sum(fn (mixed $amount): int => $this->toCents((string) $amount));
            $balanceCents = $this->toCents((string) $lockedSupply->total_amount) - $paidCents;
            $paymentCents = $this->toCents((string) $data['amount']);

            if ($paymentCents > $balanceCents) {
                throw ValidationException::withMessages([
                    'amount' => 'The payment cannot be greater than the outstanding balance.',
                ]);
            }

            $payment = new SupplierPayment($data);
            $payment->user()->associate($user);
            $lockedSupply->payments()->save($payment);

            $expense = new Expense([
                'category' => ExpenseCategory::ShopSupplies,
                'payment_method' => $payment->method,
                'amount' => $payment->amount,
                'description' => sprintf(
                    'Supplier payment to %s for %s',
                    $lockedSupply->supplier->name,
                    $lockedSupply->reference_number ?: str($lockedSupply->items_received)->limit(100),
                ),
                'spent_at' => $payment->paid_at,
            ]);
            $expense->user()->associate($user);
            $expense->supplierPayment()->associate($payment);
            $expense->save();

            return $payment;
        });
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
