<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Enums\UnitOfMeasure;
use App\Models\Category;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ItemRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique(Item::class, 'name')->ignore($this->route('item')),
            ],
            'type' => ['required', Rule::enum(ItemType::class)],
            'unit_of_measure' => ['required', Rule::enum(UnitOfMeasure::class)],
            'pack_label' => ['nullable', 'string', 'max:50'],
            'units_per_pack' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'measure_per_unit' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'category_id' => ['nullable', Rule::exists(Category::class, 'id')],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
            // Decimal, not integer: half a litre of oil is a real amount of stock.
            'stock_level' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'low_stock_alert' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'An item with this name already exists.',
            'type.required' => 'Choose whether this is a Product or a Repair.',
            'unit_of_measure.required' => 'Choose how this item is counted.',
            'units_per_pack.min' => 'A pack has to hold at least one unit.',
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // PRD §2: stock belongs to Products. A Repair is labour — there is
                // no shelf to count it on. Refuse the number rather than quietly
                // dropping one the manager deliberately typed.
                if ($this->input('type') !== ItemType::Repair->value) {
                    return;
                }

                // …unless the repair is measured. An AC gas refill is labour that
                // empties a 13 kg cylinder, and the shop still has to know how
                // much gas is left. Only a piece-counted repair carries nothing.
                if ($this->unitOfMeasure()?->isMeasured() ?? false) {
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
        // An item is counted in pieces until the shop says otherwise, matching
        // the model default — so forms and endpoints that predate measured
        // stock keep saving without sending the field.
        $unit = $this->blankToNull($this->unit_of_measure) ?? UnitOfMeasure::Piece->value;

        $normalised = [
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'unit_of_measure' => $unit,
            'stock_level' => $this->blankToNull($this->stock_level),
            'low_stock_alert' => $this->blankToNull($this->low_stock_alert),
            'selling_price' => $this->blankToNull($this->selling_price),
            'category_id' => $this->blankToNull($this->category_id),
            'pack_label' => is_string($this->pack_label) ? trim($this->pack_label) : $this->pack_label,
            'units_per_pack' => $this->blankToNull($this->units_per_pack),
            'measure_per_unit' => $this->blankToNull($this->measure_per_unit),
        ];

        // Packaging describes how a measured item is bought — a carton of four
        // 4-litre bottles. It means nothing for pieces, so switching an item
        // back to pieces clears it rather than leaving a stale pack size that
        // the receive-stock screen would happily offer.
        if (UnitOfMeasure::tryFrom((string) $unit) === UnitOfMeasure::Piece) {
            $normalised['pack_label'] = null;
            $normalised['units_per_pack'] = null;
            $normalised['measure_per_unit'] = null;
        }

        if ($normalised['pack_label'] === '') {
            $normalised['pack_label'] = null;
        }

        // PRD §1: only the owner may set what the shop paid. For anyone else the
        // field is dropped and never merged back, so a crafted request can
        // neither rewrite the costing nor blank it out. The key must stay absent
        // rather than become null, or `validated()` would null the column.
        if ($this->user()?->can(Permission::SetItemUnitCost->value) ?? false) {
            $normalised['unit_cost'] = $this->blankToNull($this->unit_cost);
        } else {
            $this->forget('unit_cost');
        }

        $this->merge($normalised);
    }

    private function unitOfMeasure(): ?UnitOfMeasure
    {
        $value = $this->input('unit_of_measure');

        return is_string($value) ? UnitOfMeasure::tryFrom($value) : null;
    }

    /**
     * Drop a field from every input bag.
     *
     * `FormRequest::validationData()` returns `all()`, which merges the query
     * string over the body bag, so removing a key from the request and JSON
     * bags alone still leaves `?field=value` reachable through `validated()`.
     */
    private function forget(string $key): void
    {
        $this->request->remove($key);
        $this->json?->remove($key);
        $this->query->remove($key);
    }

    private function blankToNull(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
