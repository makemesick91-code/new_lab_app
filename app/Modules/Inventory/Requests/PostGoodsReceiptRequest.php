<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Requests\Concerns\ValidatesGoodsReceiptInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PostGoodsReceiptRequest extends FormRequest
{
    use ValidatesGoodsReceiptInput {
        withValidator as protected baseWithValidator;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $branchId = app(BranchContext::class)->requireId();
            $goodsReceipt = $this->resolveBoundGoodsReceipt();

            if (! $goodsReceipt instanceof GoodsReceipt) {
                return;
            }

            $this->validatePostableGoodsReceipt($validator, $goodsReceipt, $branchId);
        });
    }

    protected function rejectsPostedGoodsReceiptOnUpdate(): bool
    {
        return false;
    }

    protected function rejectsCancelledGoodsReceiptOnUpdate(): bool
    {
        return false;
    }
}
