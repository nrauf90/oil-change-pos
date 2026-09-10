<?php

namespace App\Http\Requests;

use App\Enums\SaleLineType;
use App\Models\Item;
use App\Support\SaleTotalCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'customer_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:150'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'next_checkup_mileage' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // The regex is the shared grammar of a typed amount. It sits in front
            // of `numeric` because is_numeric() accepts "1e3", which the totals
            // calculator refuses — without it that row bills silently as zero.
            'labor_charge' => ['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999'],
            'misc_charge' => ['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999'],
            'discount' => ['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999'],

            'lines' => ['present', 'array', 'max:200'],
            // Existence, availability and type are checked in bulk in after():
            // a 200-line bill must not fire 200 separate exists() queries.
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.item_name' => ['required', 'string', 'max:200'],
            'lines.*.type' => ['required', Rule::enum(SaleLineType::class)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            // How much bulk stock this line drew. A record, never a billing figure.
            'lines.*.dispensed_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'lines.*.manually_charged_price' => [
                'required', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.item_name.required' => 'Every line needs a description.',
            'lines.*.manually_charged_price.required' => 'Type a price for every line.',
            'lines.*.manually_charged_price.numeric' => 'Prices must be numbers.',
            'lines.*.manually_charged_price.regex' => 'Prices must be numbers.',
            'lines.*.manually_charged_price.min' => 'Prices cannot be negative.',
            'lines.*.quantity.integer' => 'Quantity must be a whole number of units.',
            'lines.*.quantity.min' => 'Quantity must be at least 1.',
            'lines.*.dispensed_quantity.min' => 'A dispensed amount cannot be negative.',
            'lines.*.dispensed_quantity.numeric' => 'The dispensed amount must be a number.',
            'labor_charge.regex' => 'The labor charge must be a number.',
            'misc_charge.regex' => 'The miscellaneous charge must be a number.',
            'discount.regex' => 'The discount must be a number.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vehicle_plate' => $this->normalisePlate($this->input('vehicle_plate')),
            'mileage' => $this->blankToNull($this->input('mileage')),
            'next_checkup_mileage' => $this->blankToNull($this->input('next_checkup_mileage')),
            'labor_charge' => $this->money($this->input('labor_charge')),
            'misc_charge' => $this->money($this->input('misc_charge')),
            'discount' => $this->money($this->input('discount')),
            'lines' => $this->normaliseLines($this->input('lines')),
        ]);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkLinesAgainstInventory($validator),

            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // A bill with nothing on it and no labor or misc money is not a sale.
                $hasLines = filled($this->validated('lines'));
                $hasCharges = (float) $this->validated('labor_charge') > 0
                    || (float) $this->validated('misc_charge') > 0;

                if (! $hasLines && ! $hasCharges) {
                    $validator->errors()->add(
                        'lines',
                        'Add at least one item, or a labor or miscellaneous charge, before checking out.'
                    );
                }
            },
        ];
    }

    /**
     * One query for the whole bill: every linked line must point at an item that
     * still exists, is still on sale, and is of the kind the line claims it is.
     *
     * The type cross-check matters because a line filed under the wrong kind
     * silently corrupts the dashboard's product-versus-repair split.
     */
    private function checkLinesAgainstInventory(Validator $validator): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        $linked = [];

        foreach ($lines as $index => $line) {
            $declared = SaleLineType::tryFrom((string) ($line['type'] ?? ''));

            // A custom line is free text; any item_id on it is ignored downstream.
            // An unknown type already has its own error from the enum rule.
            if ($declared === null || $declared === SaleLineType::Custom) {
                continue;
            }

            if (filled($line['item_id'] ?? null)) {
                $linked[$index] = (int) $line['item_id'];
            }
        }

        if ($linked === []) {
            return;
        }

        $items = Item::query()
            ->whereIn('id', array_unique(array_values($linked)))
            ->where('is_active', true)
            ->get(['id', 'name', 'type', 'unit_of_measure', 'stock_level'])
            ->keyBy('id');

        foreach ($linked as $index => $itemId) {
            $item = $items->get($itemId);

            if ($item === null) {
                $validator->errors()->add(
                    "lines.{$index}.item_id",
                    'That inventory item is no longer available.'
                );

                continue;
            }

            if ($item->type->value !== $lines[$index]['type']) {
                $validator->errors()->add(
                    "lines.{$index}.type",
                    "\"{$item->name}\" is filed in inventory as a {$item->type->label()}, ".
                    'so this line cannot be billed as something else.'
                );
            }

            $this->requireDispensedAmount($validator, $index, $item, $lines[$index]);
        }
    }

    /**
     * A stock-tracked measured product must say how much left the drum.
     *
     * Left blank it used to draw nothing at all, so the shelf drifted upward
     * one forgotten top-up at a time and the low-stock alert never fired. The
     * amount is a stock record only — it has never been, and still is not, a
     * billing figure.
     *
     * @param  array<string, mixed>  $line
     */
    private function requireDispensedAmount(Validator $validator, int|string $index, Item $item, array $line): void
    {
        if (! $item->isMeasured() || $item->stock_level === null) {
            return;
        }

        if (filled($line['dispensed_quantity'] ?? null)) {
            return;
        }

        $validator->errors()->add(
            "lines.{$index}.dispensed_quantity",
            "Type how much \"{$item->name}\" was dispensed ({$item->unit_of_measure->label()}), ".
            'so stock stays accurate.'
        );
    }

    /**
     * Drop rows the salesperson started and abandoned: a blank template row with
     * no description, no item and no price should not fail the whole checkout.
     */
    private function normaliseLines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $cleaned = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $name = is_string($line['item_name'] ?? null) ? trim($line['item_name']) : ($line['item_name'] ?? null);
            $itemId = $this->blankToNull($line['item_id'] ?? null);
            $price = $this->money($line['manually_charged_price'] ?? null);

            if (blank($name) && blank($itemId) && blank($price)) {
                continue;
            }

            $cleaned[] = [
                'item_id' => $itemId,
                'item_name' => $name,
                'type' => $line['type'] ?? null,
                // A row the counter never touched sells one unit. Only a quantity
                // that was actually typed is validated as typed.
                'quantity' => $this->blankToNull($line['quantity'] ?? null) ?? 1,
                'dispensed_quantity' => $this->blankToNull($line['dispensed_quantity'] ?? null),
                'manually_charged_price' => $price,
            ];
        }

        return array_values($cleaned);
    }

    private function normalisePlate(mixed $plate): mixed
    {
        return is_string($plate) ? (strtoupper(trim($plate)) ?: null) : $plate;
    }

    /**
     * Strip a human's thousands separators so "1,234.56" reaches the rules as
     * the canonical "1234.56".
     *
     * Only well-formed grouping is removed. Junk and exponents are handed on
     * untouched so the `regex:` rule refuses them and the counter is told —
     * the calculator would otherwise read them as zero and bill nothing.
     */
    private function money(mixed $value): mixed
    {
        return $this->blankToNull(SaleTotalCalculator::normalise($value));
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
