<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Requests;

use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The operator's selection — PR2 §4, §12.
 *
 * An explicit, BOUNDED list of ids. There is no `all` flag and no "select
 * everything matching these filters", deliberately: a server-side "all" would
 * publish documents the operator never saw, and the count quoted on the
 * confirmation screen could not then be honest about what it covered.
 *
 * "Select all currently eligible" (§4) is a CLIENT convenience — the page ticks
 * the selectable checkboxes it is already showing and submits those ids. The
 * server still receives, and re-evaluates, every single one.
 *
 * WHAT THE CLIENT MAY NOT SEND
 * ----------------------------
 * No patient id, no branch id, no status, no checksum, no actor. All resolved
 * server-side from the staged row and the authenticated user, so a selection
 * request cannot introduce or alter a clinical fact — the same posture the
 * canonical publish requests take, where the only operator input is a label.
 */
class SelectLegacyBatchPublishItemsRequest extends FormRequest
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
            'import_ids' => ['required', 'array', 'min:1', 'max:'.LegacyBatchPublishRunService::MAX_SELECTION],
            'import_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['import_ids' => 'dokumen terpilih'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'import_ids.required' => 'Pilih minimal satu dokumen.',
            'import_ids.max' => 'Jumlah dokumen melebihi batas satu sesi publikasi.',
        ];
    }

    /** @return list<int> */
    public function importIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = (array) $this->input('import_ids', []);

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
