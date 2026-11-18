<?php

namespace App\Modules\ClinicalPhoto\Requests;

use App\Modules\ClinicalPhoto\Models\RmeClinicalPhoto;
use App\Modules\ClinicalPhoto\Services\RmeClinicalPhotoService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS memo M4.4 / M1.x, PTM PR4b (rule 242 §9) — void one clinical photo with
 * a written reason.
 *
 * Authorizes before it validates (R8A-2): the flag off or a photo outside the
 * account's read scope answers 404 (asked of the service, so Super Admin is
 * bound too); then the policy and the service's maker ≠ checker, which
 * Gate::before would otherwise let a Super Admin uploader past (403). The
 * service decides everything again under the locks.
 */
class VoidRmeClinicalPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $photos = app(RmeClinicalPhotoService::class);

        abort_unless($photos->enabled(), 404);

        $photo = $this->route('clinicalPhoto');
        $user = $this->user();

        if (! $photo instanceof RmeClinicalPhoto || $user === null) {
            return false;
        }

        abort_unless($photos->mayView($user, $photo), 404);

        return $user->can('void', $photo)
            && $photos->voidAccountRefusal($user, $photo) === null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'void_reason' => [
                'required',
                'string',
                'min:'.RmeClinicalPhotoService::VOID_REASON_MIN,
                'max:'.RmeClinicalPhotoService::VOID_REASON_MAX,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'void_reason.required' => RmeClinicalPhotoService::MESSAGE_VOID_REASON,
            'void_reason.string' => RmeClinicalPhotoService::MESSAGE_VOID_REASON,
            'void_reason.min' => RmeClinicalPhotoService::MESSAGE_VOID_REASON,
            'void_reason.max' => RmeClinicalPhotoService::MESSAGE_VOID_REASON,
        ];
    }
}
