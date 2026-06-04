<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = app(BranchContext::class)->requireId();
        $product = $this->route('product');

        return [
            'product_category_id' => ['required', 'integer', 'exists:inv_product_categories,id'],
            'product_unit_id' => ['required', 'integer', 'exists:inv_product_units,id'],
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('inv_products', 'code')
                    ->where('branch_id', $branchId)
                    ->ignore($product?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'average_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
