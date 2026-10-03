<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Release sticky triage on ONE document — PR1 §5.
 *
 * Singular, like the decision request, and carrying nothing but the id. The
 * authorization that matters — that the clearing actor holds review authority
 * for this document type and is not an uploader barred by separation of duties
 * — is enforced in the triage service through the adapter, where the canonical
 * policy truth lives. It is never a request field.
 */
class ClearLegacyReviewTriageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'import_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function importId(): int
    {
        return (int) $this->input('import_id');
    }
}
