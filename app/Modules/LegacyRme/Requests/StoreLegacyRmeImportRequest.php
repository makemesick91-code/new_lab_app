<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LEGACY-RME-PDF-1B — input boundary for a legacy RME document upload.
 *
 * These rules are the CHEAP first pass only. Everything that actually decides
 * whether the document may be archived — the date rules, the branch check, the
 * structural PDF validation, the checksum and the duplicate precheck — is
 * re-evaluated server-side in LegacyRmeImportService, because a form request
 * can only see what the client chose to send.
 *
 * In particular this request deliberately does NOT accept an earliest-native-RME
 * date, a checksum, or a page count: every one of those bounds is computed
 * server-side, and trusting a client-supplied value would defeat the rule it is
 * meant to enforce.
 */
class StoreLegacyRmeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LegacyRmeImport::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(((int) config('legacy_rme.upload.max_bytes', 20971520)) / 1024);

        return [
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('mst_patients', 'id')->whereNull('deleted_at'),
            ],
            // The EARLIEST clinical date the document shows — the
            // representative date. See LegacyRmeDateRuleService.
            'selected_rme_date' => ['required', 'date'],
            // The LATEST clinical date the document shows. Optional: a
            // single-date document simply omits it and the server collapses the
            // range onto the representative date. The ordering rule
            // (earliest <= latest) and the native-RME bound are BOTH enforced
            // in the date-rule service, not here — a `after_or_equal` rule in a
            // form request would only cover what the client chose to send.
            'latest_rme_date' => ['nullable', 'date'],
            // FIX-ROLL2-1: the archive's branch is DERIVED from the patient's
            // Nomor RM and is never operator input. This field is still
            // accepted so a mismatched value can be REJECTED explicitly rather
            // than silently discarded, but it is never the source of the
            // answer — LegacyRmeBranchResolver decides, and the service only
            // compares what was sent against it.
            'origin_branch_id' => [
                'nullable',
                'integer',
                Rule::exists('mst_branches', 'id')->whereNull('deleted_at'),
            ],
            'document' => [
                'required',
                'file',
                // mimetypes: validated from the file's own bytes, not from the
                // client-declared Content-Type or the extension.
                'mimetypes:application/pdf',
                'max:'.max(1, $maxKilobytes),
            ],
            // Operator attestations. These are a deliberate speed bump for a
            // human, NOT a security control — every rule they refer to is
            // independently enforced on the server.
            'patient_confirmation' => ['accepted'],
            'date_confirmation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'patient_id' => 'pasien',
            'selected_rme_date' => 'tanggal RME paling awal',
            'latest_rme_date' => 'tanggal RME paling akhir',
            'origin_branch_id' => 'cabang asal',
            'document' => 'dokumen PDF',
            'patient_confirmation' => 'konfirmasi pasien',
            'date_confirmation' => 'konfirmasi tanggal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimetypes' => 'Dokumen harus berupa berkas PDF.',
            'document.max' => 'Ukuran dokumen PDF melebihi batas yang diizinkan.',
            'patient_confirmation.accepted' => 'Konfirmasi kepemilikan dokumen oleh pasien wajib dicentang.',
            'date_confirmation.accepted' => 'Konfirmasi kesesuaian tanggal pada dokumen wajib dicentang.',
        ];
    }
}
