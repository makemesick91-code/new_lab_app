<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Completeness\Requests;

use App\Modules\LegacyImport\Completeness\Services\LegacyPatientArchiveCompletenessService;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — the SHAPE of the query
 * string, and only its shape.
 *
 * THIS IS NOT THE AUTHORIZATION BOUNDARY, AND `branch_id` IS THE REASON IT
 * MATTERS. Validating that `branch_id` is an integer which exists in
 * `mst_branches` says nothing about whether this actor may read that branch —
 * and on its own that is exactly the IDOR shape this codebase has had to close
 * before. The authorization decision is taken in
 * {@see LegacyPatientArchiveCompletenessService::resolveQuery()},
 * which intersects any requested branch with the actor's own authorized set and
 * silently drops a branch that is not in it. A request value can therefore only
 * ever narrow the report.
 *
 * `exists` IS STILL WORTH HAVING: it keeps a nonsense id out of the service and
 * gives the operator a field error instead of a silently ignored filter. It is
 * a usability rule, not a security one.
 */
class LegacyPatientArchiveCompletenessFilterRequest extends FormRequest
{
    /**
     * Authorization lives in the route middleware and is re-checked in the
     * controller. A read-only report's query string carries no authority of its
     * own, so there is nothing for this method to decide.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(LegacyCompletenessFilter::ALL)],
            'branch_id' => ['nullable', 'integer', 'exists:mst_branches,id'],
            // Bounded so an operator cannot paste a page of text into the
            // search box and turn a bounded LIKE into an unbounded one.
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'Filter status kelengkapan tidak dikenali.',
            'branch_id.exists' => 'Cabang yang dipilih tidak ditemukan.',
            'q.max' => 'Kata kunci pencarian terlalu panjang (maksimal 100 karakter).',
        ];
    }

    public function statusFilter(): string
    {
        return LegacyCompletenessFilter::normalize($this->string('status')->toString() ?: null);
    }

    public function requestedBranchId(): ?int
    {
        $value = $this->input('branch_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }

    public function searchTerm(): ?string
    {
        $term = trim($this->string('q')->toString());

        return $term === '' ? null : $term;
    }
}
