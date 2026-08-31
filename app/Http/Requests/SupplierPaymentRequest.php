<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Supply;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SupplierPaymentRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'reference_number' => ['nullable', 'string', 'max:150'],
            'receipt_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount')) {
                    return;
                }

                $supply = $this->route('supply');

                if (! $supply instanceof Supply) {
                    return;
                }

                $supply->loadMissing('payments');

                if ((float) $this->input('amount') > (float) $supply->balanceDue()) {
                    $validator->errors()->add('amount', 'The payment cannot be greater than the outstanding balance.');
                }
            },
        ];
    }
}
