<?php

namespace App\Modules\LabOrder\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LAB-WORKFLOW-V2 (Phase 8/9) — operational dashboard filter request.
 *
 * Read-only report filters. Permission is enforced by the route middleware
 * (view_lab_orders|manage_lab_orders); the branch filter is only honoured for
 * lab staff and is re-validated server-side against the allowed RME-enabled
 * branch set inside the dashboard service (never trusted from here).
 */
class LabWorkflowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
