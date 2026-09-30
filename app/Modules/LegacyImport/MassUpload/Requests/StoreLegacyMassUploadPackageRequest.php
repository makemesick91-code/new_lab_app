<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Package upload input.
 *
 * Deliberately minimal. The only thing an operator submits is the archive —
 * everything else (which patients, which branch, which dates) comes from the
 * manifest inside it and is resolved server-side.
 *
 * NOTE ON WHAT IS *NOT* HERE
 * --------------------------
 * There is no `import_type`, no `branch_id` and no `patient_id` field. The type
 * is fixed by the route, and the other two are authorization boundaries that a
 * request may never name (§10, §11). Their absence is the point; adding any of
 * them "for convenience" would reopen a spoofing surface.
 *
 * Authorization lives in the policy and is invoked by the controller, which
 * also knows the concrete import type. Doing it here would mean duplicating
 * that type knowledge into the request.
 */
class StoreLegacyMassUploadPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The controller authorizes against the type-aware policy immediately
        // after validation. Returning true here defers rather than skips.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) ceil(
            ((int) config('legacy_mass_upload.package.max_bytes', 524288000)) / 1024
        );

        return [
            'package' => [
                'required',
                'file',
                // Framework-level bounds. The service re-checks size, extension
                // and the real MIME from the file's own bytes, because these
                // rules alone would accept a renamed payload.
                'max:'.$maxKilobytes,
                'mimes:zip',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'package' => 'paket arsip (ZIP)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'package.required' => 'Paket arsip ZIP wajib diunggah.',
            'package.file' => 'Paket arsip harus berupa berkas.',
            'package.mimes' => 'Paket arsip harus berupa berkas ZIP.',
            'package.max' => 'Ukuran paket arsip melebihi batas yang diizinkan.',
        ];
    }

    public function package(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('package');

        return $file;
    }
}
