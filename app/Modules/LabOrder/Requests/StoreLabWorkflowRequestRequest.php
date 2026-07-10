<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-WORKFLOW-V2 — Cabang lab request creation (V2 DRAFT order).
 *
 * SPK photo + model photo are MANDATORY at creation.
 *
 * "Klinik" = "Cabang RME" (Sprint 23 Phase 23.9.1 semantics): branch_id is
 * NEVER trusted from the request — prepareForValidation() overwrites it with
 * the server-resolved BranchContext branch, which must then be an active
 * RME-enabled branch. Patient, doctor, and lab service ids are re-validated
 * server-side against that branch (searchable dropdowns are presentation
 * only, never an authorization boundary).
 */
class StoreLabWorkflowRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + controller authorize
    }

    /**
     * The order's branch is ALWAYS the server-resolved active branch. A
     * crafted branch_id (hidden input / query string) is silently replaced,
     * so cross-branch injection is impossible by construction.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['branch_id' => app(BranchContext::class)->id()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branchId = (int) $this->input('branch_id');

        return [
            // Klinik = Cabang RME aktif (canonical StoreClinicVisitRequest rule).
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],

            // Legacy clinic master reference — no longer the "Klinik" source for
            // V2 branch requests. Kept nullable for backward compatibility.
            'clinic_id' => ['nullable', 'integer', 'exists:mst_clinics,id'],

            // Doctor must be active, not deleted, and branch-compatible: bound to
            // the active RME branch (column or mst_doctor_branches pivot) or a
            // legacy unbound doctor (no branch binding at all).
            'doctor_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($branchId): void {
                    $valid = Doctor::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->where(function ($query) use ($branchId) {
                            $query->where('branch_id', $branchId)
                                ->orWhereHas('branches', fn ($q) => $q->where('mst_branches.id', $branchId))
                                ->orWhere(function ($q) {
                                    $q->whereNull('branch_id')
                                        ->whereDoesntHave('branches');
                                });
                        })
                        ->exists();

                    if (! $valid) {
                        $fail('Dokter tidak valid untuk Cabang RME yang dipilih.');
                    }
                },
            ],

            // Patient must be active, not deleted, and belong to the active RME
            // branch. Legacy unassigned patients (branch_id null) stay selectable
            // by existing design (they are visible to every RME branch); patients
            // of ANOTHER branch are rejected.
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('mst_patients', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id')),
            ],
            'medical_record_number' => ['nullable', 'string', 'max:100'],
            'order_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:order_date'],
            'priority' => ['required', Rule::in(LabOrder::PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:2000'],

            'spk_photo' => ['required', 'file', 'image', 'max:10240'],
            'model_photo' => ['required', 'file', 'image', 'max:10240'],

            'items' => ['required', 'array', 'min:1'],
            // Lab services must exist, be active, and not soft-deleted — an
            // inactive/deprecated service id crafted into the request is a 422.
            'items.*.lab_service_id' => [
                'required',
                'integer',
                Rule::exists('mst_lab_services', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'items.*.tooth_number' => ['nullable', 'string', 'max:50'],
            'items.*.shade_color_text' => ['nullable', 'string', 'max:100'],
            'items.*.material_text' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'Cabang RME aktif tidak dapat ditentukan. Hubungi admin untuk menghubungkan akun Anda ke Cabang RME.',
            'branch_id.exists' => 'Permintaan lab hanya dapat dibuat dari Cabang RME aktif.',
            'patient_id.exists' => 'Pasien tidak valid untuk Cabang RME yang dipilih.',
            'items.*.lab_service_id.exists' => 'Layanan lab tidak valid atau sudah nonaktif.',
            'spk_photo.required' => 'Foto SPK wajib diunggah.',
            'model_photo.required' => 'Foto model wajib diunggah.',
        ];
    }
}
