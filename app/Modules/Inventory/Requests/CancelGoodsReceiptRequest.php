<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Requests\Concerns\ValidatesGoodsReceiptInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class CancelGoodsReceiptRequest extends FormRequest
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
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = app(BranchContext::class)->requireId();
            $goodsReceipt = $this->resolveBoundGoodsReceipt();

            if (! $goodsReceipt instanceof GoodsReceipt) {
                return;
            }

            if ($goodsReceipt->branch_id !== $branchId) {
                $validator->errors()->add('goods_receipt', 'Penerimaan barang tidak ditemukan di cabang aktif.');
            }

            if ($goodsReceipt->isPosted() || $goodsReceipt->posted_at !== null) {
                $validator->errors()->add('goods_receipt', 'Penerimaan barang yang sudah diposting harus divoid, bukan dibatalkan.');
            }

            if ($goodsReceipt->isVoid() || $goodsReceipt->isCancelled()) {
                $validator->errors()->add('goods_receipt', 'Penerimaan barang yang sudah dibatalkan atau divoid tidak dapat diproses ulang.');
            }

            if (! $goodsReceipt->canBeCancelled()) {
                $validator->errors()->add('goods_receipt', 'Hanya penerimaan barang draft atau diajukan yang dapat dibatalkan.');
            }
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
