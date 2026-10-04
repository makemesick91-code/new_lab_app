<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Requests;

use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Publish M Items" — PR2 §4, §12.
 *
 * Carries NO item list, deliberately. The documents published are exactly the
 * PENDING item rows the server already recorded and evaluated during selection.
 * Re-sending ids here would let a client publish something it never selected,
 * and would make the confirmation screen's "Eligible now: M" unverifiable.
 *
 * The two archive labels mirror the canonical publish FormRequests exactly —
 * `title` and `description`, both optional. That is the ONLY operator input to
 * a publication: the patient, the branch, the clinical date, the source file
 * and its checksum all come from the staged row, so a batch publish cannot
 * introduce or change a single clinical fact any more than a single publish can.
 */
class ExecuteLegacyBatchPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Same bounds as the canonical single-item publish requests.
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LegacyBatchPublishRunService::MAX_PUBLISH_PASS],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['title' => 'judul arsip', 'description' => 'keterangan arsip'];
    }

    /**
     * The archive labels, shaped exactly as the canonical publish services
     * expect them.
     *
     * @return array<string, string|null>
     */
    public function archiveAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString() ?: null,
            'description' => $this->string('description')->toString() ?: null,
        ];
    }

    public function passLimit(): int
    {
        $limit = $this->input('limit');

        if (! is_numeric($limit)) {
            return LegacyBatchPublishRunService::MAX_PUBLISH_PASS;
        }

        return max(1, min((int) $limit, LegacyBatchPublishRunService::MAX_PUBLISH_PASS));
    }
}
