<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'items_received' => ['required', 'string', 'max:5000'],
            'total_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999'],
            'bill_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items_received.required' => 'Describe the material or supplies received.',
            'total_amount.gt' => 'The supply total must be greater than zero.',
            'bill_image.max' => 'The paper bill image must be 5 MB or smaller.',
        ];
    }
}
