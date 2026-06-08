<?php

namespace App\Modules\PaymentMethod\Requests;

use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim($this->input('code', ''))),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paymentMethodId = $this->route('paymentMethod')?->id;

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('mst_payment_methods', 'code')->ignore($paymentMethodId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
            ],
            'type' => [
                'required',
                'string',
                Rule::in(PaymentMethod::TYPES),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
