<?php

namespace App\Modules\Delivery\Requests;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lab_order_id' => ['required', 'exists:trx_lab_orders,id'],
            'courier_id' => ['nullable', 'exists:users,id'],
            'delivery_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lab_order_id.required' => 'Order lab wajib dipilih.',
            'lab_order_id.exists' => 'Order lab tidak valid.',
            'courier_id.exists' => 'Kurir tidak valid.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $order = LabOrder::find($this->input('lab_order_id'));

                if ($order && $order->status !== LabOrder::STATUS_QC_PASSED) {
                    $validator->errors()->add('lab_order_id', 'Hanya order yang lulus QC yang dapat masuk pengiriman.');
                }
            },
        ];
    }
}
