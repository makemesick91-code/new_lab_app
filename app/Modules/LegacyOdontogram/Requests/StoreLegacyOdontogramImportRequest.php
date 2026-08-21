<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Requests;

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FIX-04b — intake of one scanned historical odontogram chart.
 *
 * NOTE WHAT IS ABSENT: there is no `branch_id`, no `origin_branch_id` and no
 * branch field of any kind. The owning branch is DERIVED server-side from the
 * patient's Nomor RM, so there is deliberately nothing here for an operator to
 * submit and nothing for the service to have to ignore. A request that invents
 * one is simply not read.
 *
 * PDF ONLY, v1. `mimetypes:application/pdf` inspects the file's real content
 * rather than its name or the client-declared type, and the intake service
 * re-checks the `%PDF-` magic bytes afterwards — a JPEG renamed `chart.pdf` is
 * refused at the boundary rather than discovered later by a failing rasterizer.
 *
 * The confirmations are not decoration: an operator is attesting that this
 * document belongs to THIS patient and that the date is the one printed on it.
 * That attestation is the only thing standing between a mis-scanned pile of
 * paper and a patient's clinical history.
 */
class StoreLegacyOdontogramImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LegacyOdontogramImport::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(((int) config('legacy_odontogram.upload.max_bytes', 20971520)) / 1024);

        return [
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('mst_patients', 'id')->whereNull('deleted_at'),
            ],
            'selected_odontogram_date' => ['required', 'date'],
            'document' => [
                'required',
                'file',
                'mimetypes:application/pdf',
                'max:'.max(1, $maxKilobytes),
            ],
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
            'selected_odontogram_date' => 'tanggal odontogram lama',
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
