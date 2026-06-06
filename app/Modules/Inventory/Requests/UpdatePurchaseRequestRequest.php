<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesPurchaseRequestInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseRequestRequest extends FormRequest
{
    use ValidatesPurchaseRequestInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->purchaseRequestInputRules();
    }

    public function messages(): array
    {
        return $this->purchaseRequestInputMessages();
    }
}
