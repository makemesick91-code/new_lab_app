<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Requests\Concerns\ValidatesStockTransferInput;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStockTransferRequest extends FormRequest
{
    use ValidatesStockTransferInput;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->stockTransferInputRules();
    }
}
