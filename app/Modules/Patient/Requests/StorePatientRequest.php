<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Patient\Services\PatientService;
use App\Modules\PatientMerge\Services\PatientRegistrationDuplicateCheck;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4) — a request that
     * carries any paper-card field is the paper-card exception, and only an
     * account holding the permission may send one (403 otherwise; the fields are
     * never rendered for anyone else). The controller asks the policy and the
     * service re-asserts it.
     */
    public function authorize(): bool
    {
        return ! $this->hasPaperCardInput()
            || (bool) $this->user()?->can(PatientService::PAPER_CARD_PERMISSION);
    }

    /** Rule 244 §4 — whether any paper-card field was sent. */
    public function hasPaperCardInput(): bool
    {
        foreach (PatientService::PAPER_CARD_FIELDS as $field) {
            if ($this->filled($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the
            // Nomor RM is automatic and read-only: the branch it is issued under
            // is required, and no number, manual sequence or registration date is
            // accepted (PatientService also drops them; the server stamps the
            // clinical day). The Sprint 23.8 manual fields and the free override
            // are retired.
            // PR-2 review F2 (CG-05) — and only a branch the operator is working:
            // a context-bound desk account (Front Office, Admin Klinik, Kasir,
            // Perawat) may name its working branch only; the request may narrow,
            // never widen. PatientService re-asserts it (403 for a non-HTTP caller).
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
                Rule::in(app(RmeWorkingBranchScope::class)->branchIdsFor($this->user())),
            ],
            // Rule 244 §4 — the Supervisor RME paper-card exception: year,
            // sequence and reason code together or none; the note is optional
            // except for LAINNYA (rule 244 §4.3). PatientService re-checks every
            // bound.
            'paper_card_year' => [
                'nullable', 'required_with:paper_card_sequence,paper_card_reason_code,paper_card_reason_note', 'integer', 'digits:4',
                'min:'.PatientService::PAPER_CARD_MIN_YEAR,
                'max:'.app(ClinicalClock::class)->today()->format('Y'),
            ],
            'paper_card_sequence' => [
                'nullable', 'required_with:paper_card_year,paper_card_reason_code,paper_card_reason_note', 'string', 'regex:/^[0-9]{1,8}$/', 'not_regex:/^0+$/',
            ],
            'paper_card_reason_code' => [
                'nullable', 'required_with:paper_card_year,paper_card_sequence,paper_card_reason_note', 'string',
                Rule::in(array_keys(app(PatientService::class)->paperCardReasons())),
            ],
            'paper_card_reason_note' => [
                'nullable', 'required_if:paper_card_reason_code,'.PatientService::PAPER_CARD_REASON_OTHER, 'string',
                'min:'.PatientService::PAPER_CARD_REASON_MIN, 'max:'.PatientService::PAPER_CARD_REASON_MAX,
                // PR-3b review B1 — valid UTF-8 without control characters (tab,
                // line feed and carriage return allowed): the database would
                // refuse anything else, and its error carries the value.
                'regex:'.PatientService::PAPER_CARD_NOTE_PATTERN,
            ],
            'ktp_number' => ['nullable', 'string', 'max:16', Rule::unique('mst_patients', 'ktp_number')],
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'occupation' => ['nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            // Sprint 61.1 — opaque token for a KTP scan parked via the scanner
            // workflow. Attached to the patient after creation (controller).
            'ktp_scan_token' => ['nullable', 'string', 'max:64'],
            // REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — when OCR suggestions were
            // applied to this form, the operator must confirm they checked them
            // against the physical KTP. Every value is still validated by the
            // rules above exactly as manual entry; this adds the human check.
            'ktp_ocr_applied' => ['nullable', 'in:0,1'],
            'ktp_ocr_verified' => ['exclude_unless:ktp_ocr_applied,1', 'accepted'],
            // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — required only when
            // a strong duplicate is found (see withValidator).
            'duplicate_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ktp_number' => $this->normalizeKtpNumber($this->input('ktp_number')),
            'whatsapp_number' => $this->normalizeOptionalString($this->input('whatsapp_number')),
        ]);
    }

    public function messages(): array
    {
        return [
            'ktp_ocr_verified.accepted' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'ktp_ocr_verified.required' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'branch_id.required' => 'Cabang RME wajib dipilih. Nomor RM dibuat otomatis untuk cabang tersebut.',
            'branch_id.in' => 'Pasien baru hanya dapat didaftarkan di cabang tempat Anda bertugas.',
            'paper_card_year.*' => 'Tahun pada kartu lama wajib 4 digit dan tidak melewati tahun ini.',
            'paper_card_sequence.*' => 'Nomor urut kartu lama wajib angka (1–8 digit) dan bukan nol.',
            'paper_card_reason_code.required_with' => 'Pilih alasan memakai Nomor RM kartu lama.',
            'paper_card_reason_code.in' => 'Pilih alasan memakai Nomor RM kartu lama dari daftar.',
            'paper_card_reason_note.required_if' => 'Catatan wajib diisi untuk alasan Lainnya.',
            'paper_card_reason_note.min' => 'Catatan minimal :min karakter.',
            'paper_card_reason_note.max' => 'Catatan maksimal :max karakter.',
            'paper_card_reason_note.regex' => 'Catatan berisi karakter yang tidak valid.',
            'ktp_number.unique' => 'Nomor KTP sudah terdaftar pada pasien lain.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(PatientRegistrationDuplicateCheck::class)->validate(
                    $validator,
                    $this->only(['name', 'date_of_birth', 'phone', 'whatsapp_number']),
                    $this->input('duplicate_override_reason'),
                    'duplicate_override_reason',
                );
            }
        });
    }

    private function normalizeKtpNumber(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
