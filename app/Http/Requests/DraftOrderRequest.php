<?php

namespace App\Http\Requests;

use App\Enums\SaleLineType;
use App\Support\SaleTotalCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a bill that is still being built.
 *
 * Deliberately looser than {@see StoreSaleRequest}: a draft is allowed to be
 * incomplete. A car arrives, the counter saves an empty bill against the bay,
 * and the lines are typed as the work happens. What is *not* looser is the
 * shape of anything that was typed — a price is still a price.
 *
 * Note what is NOT here: `user_id`, `status`, `version` as an authority. Who
 * opened a bill comes from the session, and the version is a check, not a
 * value the client gets to set.
 */
class DraftOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:150'],
            'vehicle_plate' => ['nullable', 'string', 'max:40'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'next_checkup_mileage' => ['nullable', 'integer', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'labor_charge' => ['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999'],
            'misc_charge' => ['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999'],

            // An empty bill is valid. That is the whole point of a draft.
            'lines' => ['nullable', 'array', 'max:200'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.item_name' => ['required', 'string', 'max:200'],
            'lines.*.type' => ['required', Rule::enum(SaleLineType::class)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.dispensed_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            // Still validated as money, because a draft becomes a bill.
            'lines.*.manually_charged_price' => [
                'nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999',
            ],

            // The copy of the draft this save was based on. Absent on a create.
            'version' => ['nullable', 'integer', 'min:1'],
            // Set by the Complete button, so one request saves the latest cart
            // and bills it rather than losing whatever was typed in between.
            'complete' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.item_name.required' => 'Every line needs a name before the bill can be saved.',
            'lines.max' => 'A single bill cannot hold more than 200 lines.',
        ];
    }

    /**
     * The order's own fields, with the lines stripped out — those are written
     * through the relationship, never mass-assigned.
     *
     * @return array<string, mixed>
     */
    public function orderPayload(): array
    {
        $data = $this->safe()->except(['lines', 'version', 'complete']);

        $data['labor_charge'] = SaleTotalCalculator::amount($data['labor_charge'] ?? null);
        $data['misc_charge'] = SaleTotalCalculator::amount($data['misc_charge'] ?? null);

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(): array
    {
        return $this->safe()->array('lines');
    }

    public function wantsCompletion(): bool
    {
        return (bool) ($this->validated()['complete'] ?? false);
    }

    public function submittedVersion(): ?int
    {
        $version = $this->validated()['version'] ?? null;

        return $version === null ? null : (int) $version;
    }

    protected function prepareForValidation(): void
    {
        // The hidden field always posts, so an empty string means "no".
        if ($this->complete === '') {
            $this->merge(['complete' => null]);
        }

        foreach (['label', 'customer_name', 'phone', 'vehicle_model', 'vehicle_plate', 'notes'] as $field) {
            if (is_string($this->{$field})) {
                $trimmed = trim($this->{$field});
                $this->merge([$field => $trimmed === '' ? null : $trimmed]);
            }
        }
    }
}
