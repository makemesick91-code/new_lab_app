<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesGoodsReceiptInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoodsReceiptRequest extends FormRequest
{
    use ValidatesGoodsReceiptInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->goodsReceiptStoreRules();
    }

    public function messages(): array
    {
        return $this->goodsReceiptInputMessages();
    }
}
