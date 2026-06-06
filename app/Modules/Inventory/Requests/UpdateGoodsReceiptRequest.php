<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesGoodsReceiptInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoodsReceiptRequest extends FormRequest
{
    use ValidatesGoodsReceiptInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->goodsReceiptUpdateRules();
    }

    public function messages(): array
    {
        return $this->goodsReceiptInputMessages();
    }
}
