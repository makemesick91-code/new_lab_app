<?php

namespace App\Modules\Patient\Services;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Cross-branch Nomor RM lookup (Sprint 57, suffix usability in Sprint 57.1).
 *
 * DELIBERATE BRANCH-ISOLATION EXCEPTION. This is the ONLY place that queries
 * patients across every branch regardless of the active BranchContext. It exists
 * so staff can detect whether a Nomor RM already exists in another branch
 * (duplicate-registration prevention) and identify the patient's origin branch.
 *
 * Constraints, by design:
 *  - Matches `medical_record_number` ONLY (no name/phone/KTP search). Sprint 57.1
 *    adds a SUFFIX ("ends with") match on top of the original EXACT match so staff
 *    can type just the last digits of a long Nomor RM. Exact match is tried first.
 *  - Read-only: returns a small, privacy-safe array. Never mutates anything and
 *    never grants cross-branch edit/visit/payment actions.
 *  - Exposes ONLY non-sensitive identity fields. NEVER ktp_number, whatsapp,
 *    phone, email, address, or any clinical detail.
 *
 * Do NOT reuse this for normal listing — branch-scoped index queries must stay
 * scoped via their existing services.
 */
class CrossBranchPatientLookupService
{
    /**
     * Minimum suffix length before a broad "ends with" search runs. RM format is
     * DG-{CABANG}-{TAHUN}-{NOMOR}; the manual tail is typically 4 digits (e.g. 0001),
     * so 4 lets staff type what they know while keeping cross-branch matches narrow.
     */
    public const MIN_SUFFIX_LENGTH = 4;

    /** Max safe candidates rendered for a suffix match before asking for more digits. */
    public const DISPLAY_LIMIT = 10;

    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * Look up patients by Nomor RM across ALL branches.
     *
     * Resolution order: exact match first; if none, a suffix ("ends with") match
     * when the input is at least {@see MIN_SUFFIX_LENGTH} characters.
     *
     * @return array{
     *     searched: bool,
     *     query: string,
     *     results: array<int, array{
     *         medical_record_number: string,
     *         name: string,
     *         branch_label: string,
     *         is_active: bool,
     *         latest_visit_date: ?string,
     *         is_current_branch: bool
     *     }>,
     *     is_duplicate: bool,
     *     match_type: ?string,
     *     too_short: bool,
     *     too_many: bool,
     *     min_length: int
     * }
     */
    public function lookupByMedicalRecordNumberAcrossBranches(?string $rm): array
    {
        $rm = trim((string) $rm);

        if ($rm === '') {
            return $this->emptyPayload('');
        }

        // 1) Exact match first — preserves Sprint 57 full-RM behaviour verbatim.
        $exact = $this->mapResults(
            $this->scopedPatientQuery()
                ->where('medical_record_number', $rm) // intentionally no branch filter
                ->with('branch:id,code,name')
                ->limit(self::DISPLAY_LIMIT)
                ->get()
        );

        if (count($exact) > 0) {
            return $this->emptyPayload($rm, [
                'searched' => true,
                'results' => $exact,
                'is_duplicate' => count($exact) > 1, // genuine duplicate RM
                'match_type' => 'exact',
            ]);
        }

        // 2) No exact hit: guard suffix breadth by minimum input length.
        if (mb_strlen($rm) < self::MIN_SUFFIX_LENGTH) {
            return $this->emptyPayload($rm, ['searched' => true, 'too_short' => true]);
        }

        // 3) Suffix ("ends with") match across ALL branches; wildcards escaped.
        $suffix = $this->scopedPatientQuery()
            ->where('medical_record_number', 'LIKE', '%'.$this->escapeLike($rm))
            ->with('branch:id,code,name')
            ->limit(self::DISPLAY_LIMIT + 1) // +1 sentinel to detect "too many"
            ->get();

        if ($suffix->count() > self::DISPLAY_LIMIT) {
            return $this->emptyPayload($rm, ['searched' => true, 'too_many' => true]);
        }

        return $this->emptyPayload($rm, [
            'searched' => true,
            'results' => $this->mapResults($suffix),
            'match_type' => 'suffix',
        ]);
    }

    /**
     * Build the canonical payload, overriding only the keys provided. Keeps every
     * call site returning the exact same shape (additive over Sprint 57).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function emptyPayload(string $query, array $overrides = []): array
    {
        return array_merge([
            'searched' => false,
            'query' => $query,
            'results' => [],
            'is_duplicate' => false,
            'match_type' => null,
            'too_short' => false,
            'too_many' => false,
            'min_length' => self::MIN_SUFFIX_LENGTH,
        ], $overrides);
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @return array<int, array<string, mixed>>
     */
    private function mapResults($patients): array
    {
        $currentBranchId = $this->branchContext->id();

        return $patients->map(fn (Patient $patient): array => [
            'medical_record_number' => (string) $patient->medical_record_number,
            'name' => (string) $patient->name,
            'branch_label' => $patient->branchLabel(),
            'is_active' => (bool) $patient->is_active,
            'latest_visit_date' => $this->latestVisitDate($patient->id),
            'is_current_branch' => $currentBranchId !== null && $patient->branch_id === $currentBranchId,
        ])->all();
    }

    /** Escape LIKE metacharacters so suffix input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Base patient query for lookup; doctors only see patients in their RM scope.
     *
     * @return Builder<Patient>
     */
    private function scopedPatientQuery()
    {
        $query = Patient::query()
            ->select(['id', 'name', 'medical_record_number', 'branch_id', 'is_active']);

        $user = Auth::user();

        if ($user !== null) {
            $query = $this->doctorScope->applyPatientScopeForUser($user, $query);
        }

        return $query;
    }

    private function latestVisitDate(int $patientId): ?string
    {
        $date = ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->max('visit_date');

        return $date ? Carbon::parse($date)->format('d M Y') : null;
    }
}
