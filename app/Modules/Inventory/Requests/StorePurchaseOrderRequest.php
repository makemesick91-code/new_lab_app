<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesPurchaseOrderInput;
use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseOrderRequest extends FormRequest
{
    use ValidatesPurchaseOrderInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->purchaseOrderInputRules();
    }

    public function messages(): array
    {
        return $this->purchaseOrderInputMessages();
    }
}
