<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class QuickItemRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique(Item::class, 'name')],
            'type' => ['required', Rule::enum(ItemType::class)],
            'is_universal' => ['sometimes', 'boolean'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'stock_level' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'compatibilities' => ['nullable', 'array'],
            'compatibilities.*.vehicle_make_id' => ['nullable', 'integer', Rule::exists(VehicleMake::class, 'id')],
            'compatibilities.*.vehicle_make_name' => ['nullable', 'string', 'max:100'],
            'compatibilities.*.vehicle_model_id' => ['nullable', 'integer', Rule::exists(VehicleModel::class, 'id')],
            'compatibilities.*.vehicle_model_name' => ['nullable', 'string', 'max:100'],
            'compatibilities.*.year_from' => ['nullable', 'integer'],
            'compatibilities.*.year_to' => ['nullable', 'integer'],
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
                if ($this->input('type') === ItemType::Repair->value) {
                    foreach (['stock_level', 'low_stock_alert'] as $field) {
                        if (filled($this->input($field))) {
                            $validator->errors()->add($field, 'A repair task does not carry stock.');
                        }
                    }

                    return;
                }

                if ($validator->errors()->hasAny(['type', 'is_universal', 'compatibilities'])) {
                    return;
                }

                if ($this->input('type') !== ItemType::Product->value || $this->boolean('is_universal')) {
                    return;
                }

                $compatibilities = $this->compatibilities();

                if ($compatibilities === []) {
                    $validator->errors()->add(
                        'compatibilities',
                        'Add at least one compatible vehicle for a vehicle-specific product.',
                    );

                    return;
                }

                foreach ($compatibilities as $index => $compatibility) {
                    $this->validateCompatibilityRow($validator, $index, $compatibility);
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

        $itemType = is_string($this->type) ? ItemType::tryFrom($this->type) : null;

        if ($itemType === ItemType::Product) {
            $normalised['is_universal'] = $this->has('is_universal')
                ? $this->boolean('is_universal')
                : true;

            $normalised['compatibilities'] = $normalised['is_universal']
                ? []
                : $this->preparedCompatibilities();
        } else {
            $this->forget('is_universal');
            $this->forget('compatibilities');
        }

        if ($this->user()?->can(Permission::SetItemUnitCost->value) ?? false) {
            $normalised['unit_cost'] = $this->blankToNull($this->unit_cost);
        } else {
            $this->forget('unit_cost');
        }

        $this->merge($normalised);
    }

    /** @return list<array{vehicle_make_id: mixed, vehicle_make_name: mixed, vehicle_model_id: mixed, vehicle_model_name: mixed, year_from: mixed, year_to: mixed}> */
    public function compatibilities(): array
    {
        $value = $this->input('compatibilities');

        return is_array($value) ? array_values($value) : [];
    }

    /** @return list<array{vehicle_make_id: int|null, vehicle_make_name: ?string, vehicle_model_id: int|null, vehicle_model_name: ?string, year_from: int|null, year_to: int|null}> */
    public function validatedCompatibilities(): array
    {
        return collect($this->validated('compatibilities', []))
            ->filter(fn (mixed $compatibility): bool => is_array($compatibility))
            ->map(function (array $compatibility): array {
                return [
                    'vehicle_make_id' => $this->nullableInteger(Arr::get($compatibility, 'vehicle_make_id')),
                    'vehicle_make_name' => $this->nullableString(Arr::get($compatibility, 'vehicle_make_name')),
                    'vehicle_model_id' => $this->nullableInteger(Arr::get($compatibility, 'vehicle_model_id')),
                    'vehicle_model_name' => $this->nullableString(Arr::get($compatibility, 'vehicle_model_name')),
                    'year_from' => $this->nullableInteger(Arr::get($compatibility, 'year_from')),
                    'year_to' => $this->nullableInteger(Arr::get($compatibility, 'year_to')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $compatibility
     */
    private function validateCompatibilityRow(Validator $validator, int $index, array $compatibility): void
    {
        $makeId = Arr::get($compatibility, 'vehicle_make_id');
        $makeName = Arr::get($compatibility, 'vehicle_make_name');
        $modelId = Arr::get($compatibility, 'vehicle_model_id');
        $modelName = Arr::get($compatibility, 'vehicle_model_name');
        $yearFrom = Arr::get($compatibility, 'year_from');
        $yearTo = Arr::get($compatibility, 'year_to');
        $maximumYear = 2026;

        if ($modelId === null && $modelName === null) {
            $validator->errors()->add(
                "compatibilities.{$index}.vehicle_model_id",
                'Choose a model or type a new one.',
            );
        }

        if ($makeId === null && $makeName === null) {
            $validator->errors()->add(
                "compatibilities.{$index}.vehicle_make_id",
                'Choose a make or type a new one.',
            );
        }

        foreach (['year_from' => $yearFrom, 'year_to' => $yearTo] as $field => $year) {
            if ($year !== null && ($year < 2000 || $year > $maximumYear)) {
                $validator->errors()->add(
                    "compatibilities.{$index}.{$field}",
                    'The year must be between 2000 and 2026.',
                );
            }
        }

        if ($yearFrom !== null && $yearTo !== null && $yearFrom > $yearTo) {
            $validator->errors()->add(
                "compatibilities.{$index}.year_to",
                'The ending year must be after or equal to the starting year.',
            );
        }
    }

    /** @return list<array{vehicle_make_id: mixed, vehicle_make_name: ?string, vehicle_model_id: mixed, vehicle_model_name: ?string, year_from: mixed, year_to: mixed}> */
    private function preparedCompatibilities(): array
    {
        $value = $this->input('compatibilities');

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $compatibility): bool => is_array($compatibility))
            ->map(function (array $compatibility): array {
                return [
                    'vehicle_make_id' => $this->blankToNull(Arr::get($compatibility, 'vehicle_make_id')),
                    'vehicle_make_name' => $this->nullableString(Arr::get($compatibility, 'vehicle_make_name')),
                    'vehicle_model_id' => $this->blankToNull(Arr::get($compatibility, 'vehicle_model_id')),
                    'vehicle_model_name' => $this->nullableString(Arr::get($compatibility, 'vehicle_model_name')),
                    'year_from' => $this->blankToNull(Arr::get($compatibility, 'year_from')),
                    'year_to' => $this->blankToNull(Arr::get($compatibility, 'year_to')),
                ];
            })
            ->values()
            ->all();
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
