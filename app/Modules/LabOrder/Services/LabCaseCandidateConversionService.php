<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabService\Models\LabService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LabCaseCandidateConversionService
{
    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly LabOrderService $labOrderService,
        private readonly LabOrderRepositoryInterface $labOrders,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function convertToLabOrder(LabCaseCandidate $candidate, array $payload, ?User $actor = null): LabOrder
    {
        $actor = $actor ?? auth()->user();
        $branchId = $this->branchContext->requireId();

        if ($candidate->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'candidate' => 'Kandidat tidak ditemukan di cabang aktif.',
            ]);
        }

        return DB::transaction(function () use ($candidate, $payload, $actor, $branchId) {
            $locked = LabCaseCandidate::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isConverted() && $locked->converted_lab_order_id) {
                return LabOrder::query()->findOrFail($locked->converted_lab_order_id);
            }

            if (! $locked->isPendingReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya kandidat berstatus menunggu review yang dapat dikonversi.',
                ]);
            }

            $labService = $this->resolveLabService($payload['lab_service_id'] ?? null);
            $quantity = (int) ($payload['quantity'] ?? $locked->quantity ?? 1);
            $dueDate = $payload['due_date'] ?? null;

            if (! $dueDate) {
                throw ValidationException::withMessages([
                    'due_date' => 'Tenggat wajib diisi.',
                ]);
            }

            $locked->loadMissing(['clinicVisit', 'patient']);

            $clinicId = $locked->clinicVisit?->clinic_id;
            if (! $clinicId) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan klinik pada kandidat tidak memiliki klinik yang valid.',
                ]);
            }

            $itemNotes = trim(collect([
                $locked->source_description,
                $payload['notes'] ?? null,
            ])->filter()->implode("\n"));

            $order = $this->labOrderService->create([
                'clinic_id' => $clinicId,
                'doctor_id' => $locked->doctor_id,
                'patient_id' => $locked->patient_id,
                'medical_record_number' => $locked->patient?->medical_record_number,
                'order_date' => now()->toDateString(),
                'due_date' => $dueDate,
                'priority' => 'NORMAL',
                'notes' => $payload['notes'] ?? null,
                'items' => [[
                    'lab_service_id' => $labService->id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $labService->price,
                    'notes' => $itemNotes !== '' ? $itemNotes : null,
                ]],
            ], $actor);

            $this->labOrders->update($order, ['branch_id' => $branchId]);

            $locked->update([
                'status' => LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER,
                'converted_lab_order_id' => $order->id,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
                'metadata' => array_merge($locked->metadata ?? [], [
                    'conversion' => [
                        'lab_service_id' => $labService->id,
                        'converted_at' => now()->toIso8601String(),
                        'converted_by' => $actor?->id,
                    ],
                ]),
            ]);

            return $order->refresh();
        });
    }

    private function resolveLabService(mixed $labServiceId): LabService
    {
        if (! $labServiceId) {
            throw ValidationException::withMessages([
                'lab_service_id' => 'Layanan lab wajib dipilih secara eksplisit.',
            ]);
        }

        $labService = LabService::query()->find((int) $labServiceId);

        if (! $labService) {
            throw ValidationException::withMessages([
                'lab_service_id' => 'Layanan lab tidak ditemukan.',
            ]);
        }

        if (! $labService->is_active) {
            throw ValidationException::withMessages([
                'lab_service_id' => 'Layanan lab tidak aktif.',
            ]);
        }

        return $labService;
    }
}
