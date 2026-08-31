<?php

namespace App\Http\Requests;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a multi-point inspection sheet.
 *
 * Note what is NOT here: inspected_by / user_id. The inspector is stamped from
 * the session in the controller, so a forged id in the body is simply never
 * read. Nor is there any price rule — an inspection has no money on it.
 */
class InspectionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_id' => ['nullable', 'integer', Rule::exists('sales', 'id')],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vehicle_plate' => ['required', 'string', 'max:40'],
            'vehicle_model' => ['nullable', 'string', 'max:150'],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // A "multi-point inspection" with no points recorded is not a report.
            'points' => ['required', 'array', 'min:1'],
            'points.*.status' => ['required', Rule::enum(InspectionStatus::class)],
            'points.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle_plate.required' => 'Which car is this? Enter the licence plate.',
            'points.required' => 'Record a verdict on at least one check-point.',
            'points.*.status.enum' => 'Mark each check-point OK, Needs attention or Urgent.',
            'mileage.integer' => 'Mileage must be a whole number of kilometres.',
            'mileage.min' => 'Mileage cannot be negative.',
        ];
    }

    /**
     * The sheet lists every check-point, but the technician only taps the ones
     * they actually looked at. Rows with no verdict are dropped here, so an
     * untouched point never trips the "status is required" rule and never
     * reaches the database as a half-filled row.
     */
    protected function prepareForValidation(): void
    {
        $points = $this->input('points');

        $this->merge([
            'vehicle_plate' => is_string($this->vehicle_plate) ? trim($this->vehicle_plate) : $this->vehicle_plate,
            'mileage' => $this->mileage === '' ? null : $this->mileage,
            'sale_id' => $this->sale_id === '' ? null : $this->sale_id,
            'points' => is_array($points)
                ? array_filter($points, fn ($values) => filled(is_array($values) ? ($values['status'] ?? null) : $values))
                : $points,
        ]);
    }

    /**
     * Reject a check-point key the shop's procedure does not recognise, so a
     * hand-rolled POST cannot invent a point that no screen can ever render.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $unknown = array_diff(
                    array_keys((array) $this->input('points', [])),
                    InspectionPoint::values(),
                );

                if ($unknown !== []) {
                    $validator->errors()->add(
                        'points',
                        'Unknown check-point: '.implode(', ', $unknown).'.',
                    );
                }
            },
        ];
    }

    /**
     * The check-points that were actually tapped.
     *
     * @return array<string, array{status: string, note?: string|null}>
     */
    public function points(): array
    {
        return (array) $this->validated('points', []);
    }

    /** Everything that lives on the inspections row itself. @return array<string, mixed> */
    public function details(): array
    {
        return collect($this->validated())->except('points')->all();
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['vehicle_plate' => 'licence plate'];
    }
}
