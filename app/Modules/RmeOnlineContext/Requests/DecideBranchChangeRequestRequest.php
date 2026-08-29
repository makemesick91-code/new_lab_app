<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — a Super Admin decision on a pending
 * working-branch change request.
 *
 * The decision itself (approve or reject) is carried by the ROUTE, not by a
 * field, so there is no `status` a client could set. The only optional payload
 * is a free-text note recorded on the audit trail.
 */
class DecideBranchChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
