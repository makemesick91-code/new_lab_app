<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Requests;

use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ONE reviewer attestation about ONE document — PR1 §1, §4.
 *
 * DELIBERATELY SINGULAR. There is no `import_ids` array and no `all` flag, and
 * that is the structural guarantee behind "no blind Review All": the only shape
 * this endpoint accepts names exactly one import. A request cannot express
 * "mark the queue reviewed" because the vocabulary to say it does not exist.
 *
 * WHAT THE CLIENT MAY NOT SEND
 * ----------------------------
 * No patient id, no branch id, no status, no source checksum, no actor. All of
 * those are resolved server-side from the staged row and the authenticated
 * user. A decision request therefore cannot introduce or alter a single
 * clinical fact — the same posture the canonical publish requests take, where
 * the only operator input is a label.
 *
 * `pages_viewed` is accepted but is EVIDENCE ONLY (§13). No gate reads it. It is
 * clamped rather than trusted, and a client that lies about it gains nothing,
 * because the authoritative signal is the attestation itself.
 */
class RecordLegacyBatchReviewDecisionRequest extends FormRequest
{
    /** Authorization is the controller's, against the canonical policy + scope. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'import_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', Rule::in(LegacyBatchReviewDecision::all())],

            // Required only when the decision withholds the document. Validated
            // against the closed list the UI offered, so a crafted code cannot
            // introduce a reason no operator could have chosen.
            'reason_code' => [
                'nullable',
                'string',
                Rule::in(LegacyBatchReviewReason::triageCodes()),
                Rule::requiredIf(fn (): bool => LegacyBatchReviewDecision::isTriaging(
                    $this->input('decision')
                )),
            ],

            'reason_note' => ['nullable', 'string', 'max:2000'],
            'pages_viewed' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // Where to go next, so the workspace can keep the reviewer moving
            // without a round trip through the index. Validated as a bare id;
            // the controller still resolves it through the canonical scope.
            'next_import_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'import_id' => 'dokumen',
            'decision' => 'keputusan tinjauan',
            'reason_code' => 'alasan',
            'reason_note' => 'catatan',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason_code.required' => 'Alasan wajib dipilih saat menahan dokumen.',
            'reason_code.in' => 'Alasan tidak dikenal.',
        ];
    }

    public function importId(): int
    {
        return (int) $this->input('import_id');
    }

    public function decision(): string
    {
        return (string) $this->input('decision');
    }

    public function reasonCode(): ?string
    {
        $code = $this->input('reason_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function reasonNote(): ?string
    {
        $note = $this->input('reason_note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }

    public function pagesViewed(): ?int
    {
        $pages = $this->input('pages_viewed');

        return is_numeric($pages) ? (int) $pages : null;
    }

    public function nextImportId(): ?int
    {
        $next = $this->input('next_import_id');

        return is_numeric($next) ? (int) $next : null;
    }
}
