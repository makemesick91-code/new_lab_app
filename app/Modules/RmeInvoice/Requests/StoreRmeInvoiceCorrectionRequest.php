<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionProposalBuilder;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionApprovalReference;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — SHAPE of a correction
 * submission. Authorization is the policy (controller) and the service; every
 * business rule (ownership of original items, Rp0, totals, treatment state) is
 * re-checked in the service, so this request only keeps garbage out early.
 *
 * Deliberately NOT accepted: branch_id, subtotal, total, difference, operation,
 * status. A submitted value for any of them is simply never read.
 */
class StoreRmeInvoiceCorrectionRequest extends FormRequest
{
    /**
     * Validation runs BEFORE the controller, so without this a malformed payload
     * would answer a validation error while the capability is off — confirming
     * an unfinished endpoint exists. Off means 404 here too, like the controller.
     */
    protected function prepareForValidation(): void
    {
        abort_unless(app(RmeInvoiceCorrectionFeatureGuard::class)->enabled(), 404);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['regex:/^\d{1,13}(\.\d{1,2})?$/D'];

        return [
            'reason' => ['required', 'string', 'min:'.RmeInvoiceCorrectionService::REASON_MIN, 'max:'.RmeInvoiceCorrectionService::REASON_MAX],
            'idempotency_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
            // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — shape only (rule 202).
            // Whether it is REQUIRED depends on the correction type, which the
            // service derives from the ledger under the invoice lock.
            'owner_approval_reference' => ['nullable', 'string', 'max:'.RmeInvoiceCorrectionApprovalReference::MAX],
            'lines' => ['required', 'array', 'min:1', 'max:'.RmeInvoiceCorrectionProposalBuilder::MAX_LINES],
            'lines.*.original_item_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.treatment_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.doctor_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:'.RmeInvoiceCorrectionProposalBuilder::MAX_QTY],
            'lines.*.unit_price' => array_merge(['required'], $money),
            'lines.*.discount' => array_merge(['nullable'], $money),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan koreksi wajib diisi.',
            'reason.min' => 'Alasan koreksi minimal '.RmeInvoiceCorrectionService::REASON_MIN.' karakter.',
            'lines.required' => 'Minimal satu item tagihan wajib ada setelah koreksi.',
            'lines.min' => 'Minimal satu item tagihan wajib ada setelah koreksi.',
            'lines.*.description.required' => 'Deskripsi item wajib diisi.',
            'lines.*.qty.min' => 'Qty minimal 1.',
            'lines.*.unit_price.required' => 'Harga satuan wajib diisi.',
            'lines.*.unit_price.regex' => 'Harga satuan harus angka tidak negatif dengan maksimal 2 desimal.',
            'lines.*.discount.regex' => 'Diskon harus angka tidak negatif dengan maksimal 2 desimal.',
        ];
    }

    /**
     * Only the keys the service reads.
     *
     * @return array{reason: string, idempotency_key: string|null, owner_approval_reference: string|null, lines: array<int, array<string, mixed>>}
     */
    public function correctionData(): array
    {
        $validated = $this->validated();

        return [
            'reason' => (string) $validated['reason'],
            'idempotency_key' => $validated['idempotency_key'] ?? null,
            'owner_approval_reference' => $validated['owner_approval_reference'] ?? null,
            'lines' => array_values(array_map(fn (array $line) => [
                'original_item_id' => $line['original_item_id'] ?? null,
                'treatment_id' => $line['treatment_id'] ?? null,
                'doctor_id' => $line['doctor_id'] ?? null,
                'description' => $line['description'],
                'qty' => $line['qty'],
                'unit_price' => (string) $line['unit_price'],
                'discount' => isset($line['discount']) && $line['discount'] !== '' ? (string) $line['discount'] : '0',
            ], $validated['lines'])),
        ];
    }
}
