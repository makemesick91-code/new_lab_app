<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Requests;

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — input boundary for a
 * legacy ODONTOGRAM document uploaded FROM a real visit.
 *
 * ONE DATE, NOT A RANGE, and that is not an oversight. The legacy odontogram
 * archive models a document as a single representative clinical date
 * (`selected_odontogram_date`); it has no latest-date column and no range
 * semantics. Accepting a second date here to mirror the RME form would create
 * an input with nowhere to go and nothing to validate it against.
 *
 * NO `source_rm_raw` EITHER, for the same kind of reason: the odontogram
 * archive has no source-RM binding service — that control was built for the
 * RME archive (LEGACY-RME-SOURCE-RM-BINDING-1) and never extended here.
 * Inventing a field the intake would silently discard would suggest a
 * protection that does not exist.
 *
 * As with the RME form, everything that decides whether the document may be
 * archived is re-evaluated server-side; this is the cheap first pass only.
 */
class StoreVisitBoundLegacyOdontogramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return $user->can('create', LegacyOdontogramImport::class)
            && $user->can('verify_legacy_dates_at_ingestion');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(
            ((int) config('legacy_odontogram.upload.max_bytes', config('legacy_rme.upload.max_bytes', 20971520))) / 1024
        );

        return [
            'selected_odontogram_date' => ['required', 'date'],

            'document' => [
                'required',
                'file',
                'mimetypes:application/pdf',
                'max:'.max(1, $maxKilobytes),
            ],

            'date_attestation' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_attestation.accepted' => 'Anda harus menyatakan bahwa tanggal yang dimasukkan sesuai dengan dokumen asli.',
        ];
    }
}
