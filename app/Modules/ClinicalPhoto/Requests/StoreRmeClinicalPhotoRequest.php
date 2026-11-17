<?php

namespace App\Modules\ClinicalPhoto\Requests;

use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use App\Modules\ClinicalPhoto\Support\ClinicalPhotoNormalizer;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — one clinical photo for a visit.
 *
 * Authorizes before it validates (R8A-2): with the flag off the route answers
 * 404; then the policy, and the service's own account predicate as well,
 * because Gate::before lets Super Admin past the policy and the uploader
 * allowlist must refuse it before its payload is even shape-checked. The rules
 * are the outer shape only (a file within the byte bound whose content sniffs
 * as JPEG or PNG); ClinicalPhotoNormalizer decides the image itself in the
 * service, before any write.
 */
class StoreRmeClinicalPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $photos = app(RmeClinicalPhotoService::class);

        abort_unless($photos->enabled(), 404);

        $visit = $this->route('clinicVisit');
        $user = $this->user();

        return $visit instanceof ClinicVisit
            && $user !== null
            && $user->can('upload', [RmeClinicalPhoto::class, $visit])
            && $photos->uploadAccountRefusal($user, $visit) === null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'max:'.intdiv(ClinicalPhotoNormalizer::MAX_UPLOAD_BYTES, 1024),
                'mimes:jpg,jpeg,png',
                'mimetypes:image/jpeg,image/png',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'photo.required' => ClinicalPhotoNormalizer::MESSAGE_REQUIRED,
            'photo.file' => ClinicalPhotoNormalizer::MESSAGE_TYPE,
            'photo.max' => ClinicalPhotoNormalizer::MESSAGE_TOO_LARGE,
            'photo.mimes' => ClinicalPhotoNormalizer::MESSAGE_TYPE,
            'photo.mimetypes' => ClinicalPhotoNormalizer::MESSAGE_TYPE,
        ];
    }
}
