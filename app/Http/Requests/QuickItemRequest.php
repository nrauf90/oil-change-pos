<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ItemType;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuickItemRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('items', 'name')],
            'type' => ['required', Rule::enum(ItemType::class)],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'stock_level' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0', 'max:99999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'An item with this name already exists.',
            'type.required' => 'Choose whether this is a Product or a Repair.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // PRD §2: stock belongs to Products. A Repair is labour — there is
                // no shelf to count it on. Refuse the number rather than quietly
                // dropping one the counter deliberately typed.
                if ($this->input('type') !== ItemType::Repair->value) {
                    return;
                }

                foreach (['stock_level', 'low_stock_alert'] as $field) {
                    if (filled($this->input($field))) {
                        $validator->errors()->add($field, 'A repair task does not carry stock.');
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalised = [
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'stock_level' => $this->blankToNull($this->stock_level),
            'low_stock_alert' => $this->blankToNull($this->low_stock_alert),
        ];

        // PRD §1: only the owner may set what the shop paid. For anyone else the
        // field is dropped and never merged back, so a crafted request can
        // neither rewrite the costing nor blank it out. The key must stay absent
        // rather than become null, or `validated()` would null the column.
        if ($this->user()?->can(Permission::SetItemUnitCost->value) ?? false) {
            $normalised['unit_cost'] = $this->blankToNull($this->unit_cost);
        } else {
            $this->request->remove('unit_cost');
            $this->json?->remove('unit_cost');
        }

        $this->merge($normalised);
    }

    private function blankToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
