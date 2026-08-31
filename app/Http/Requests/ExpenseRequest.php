<?php

namespace App\Http\Requests;

use App\Enums\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Note what is NOT here: `user_id`. Who logged an outlay is read from the
 * session in the controller, so a hand-crafted POST cannot pin a shop expense
 * on somebody else.
 */
class ExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:500'],
            'spent_at' => ['nullable', 'date', 'before_or_equal:'.now()->endOfDay()->toDateTimeString()],
        ];
    }

    public function messages(): array
    {
        return [
            'category.required' => 'Choose what this money was spent on.',
            'amount.required' => 'Enter the amount that left the drawer.',
            'amount.min' => 'An expense cannot be a negative amount.',
            'spent_at.before_or_equal' => 'An expense cannot be logged in the future.',
        ];
    }

    /**
     * The fields to write, with an unstated `spent_at` left out entirely — on a
     * create the model falls back to now(), and on an edit the original moment
     * the money left the drawer is preserved.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (($data['spent_at'] ?? null) === null) {
            unset($data['spent_at']);
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->description)) {
            $this->merge(['description' => trim($this->description)]);
        }

        if (is_string($this->spent_at) && trim($this->spent_at) === '') {
            $this->merge(['spent_at' => null]);
        }
    }
}
