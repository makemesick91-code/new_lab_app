<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Requests;

use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Submit 74 Reviewed Items" — PR1 §13.
 *
 * Carries NO item list, deliberately. The items submitted are exactly the
 * decision rows this reviewer already attested in this session; re-sending ids
 * would let a client submit something it never marked, which is the whole
 * failure mode §13 warns about ("It must NOT mean: the actor never opened them
 * but clicked Review All").
 *
 * So the server reads its own record of what was attested. The only knob is a
 * bounded pass size, and even that is clamped to the service's own maximum.
 */
class SubmitLegacyBatchReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.LegacyBatchReviewSessionService::MAX_SUBMIT_PASS,
            ],
        ];
    }

    public function passLimit(): int
    {
        $limit = $this->input('limit');

        if (! is_numeric($limit)) {
            return LegacyBatchReviewSessionService::MAX_SUBMIT_PASS;
        }

        return max(1, min((int) $limit, LegacyBatchReviewSessionService::MAX_SUBMIT_PASS));
    }
}
