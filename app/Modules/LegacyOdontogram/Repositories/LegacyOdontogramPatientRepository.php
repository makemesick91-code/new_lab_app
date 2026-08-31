<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Repositories;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — patient reads for the legacy
 * odontogram intake workspace.
 *
 * WHY A MODULE-OWNED REPOSITORY RATHER THAN CrossBranchPatientLookupService.
 * That service is the canonical cross-branch Nomor RM lookup and its RULES are
 * reused verbatim here — its MIN_SUFFIX_LENGTH and DISPLAY_LIMIT constants are
 * referenced rather than re-declared, and the exact-before-suffix order and
 * LIKE escaping match it — so there is one contract, not two. What is NOT
 * reused is its shape: it resolves the actor through the `Auth` facade, runs a
 * `latest_visit_date` query per row for a UI this page does not have, and
 * deliberately omits the surrogate key that selecting a patient requires. This
 * module already keeps its own scope for exactly this kind of reason
 * (see LegacyOdontogramWorkspaceScope), and reusing the payload shape here
 * would mean re-deriving the id afterwards by matching on Nomor RM — the same
 * indirection that makes a duplicate RM ambiguous.
 *
 * BRANCH ISOLATION IS NOT THIS CLASS'S JOB. Patient IDENTITY is deliberately
 * global: a chart may belong to a patient registered at another branch, and the
 * operator must be able to see who that is. The ARCHIVE's branch is a separate
 * decision, derived from the patient's own Nomor RM and re-checked against the
 * operator's scope by LegacyOdontogramBranchBindingService. Finding a patient
 * here never grants the right to file evidence against them.
 *
 * The doctor patient scope is still applied. Doctors hold only the read-side
 * archive permission today and cannot reach this page at all, but if that ever
 * changes, selecting a patient here must not become a wider door into patient
 * data than the doctor's own RME scope already allows.
 */
class LegacyOdontogramPatientRepository implements LegacyOdontogramPatientRepositoryInterface
{
    /**
     * Identity, plus the ONE extra column the archive's own rules read.
     *
     * Adding a column here is the one way KTP/NIK, a phone number or an address
     * could reach this workspace, so the list is explicit and every entry has to
     * justify itself.
     *
     * `date_of_birth` is NOT rendered — LegacyOdontogramPatientIdentity is a
     * final readonly DTO with four fixed fields and has no slot for it. It is
     * selected because LegacyOdontogramDateRuleService::evaluate() reads
     * `$patient->date_of_birth` to refuse an archive dated before the patient
     * was born. Eloquent returns null for an unselected attribute rather than
     * throwing (strict attribute access is not enabled on this codebase), and
     * that rule SKIPS on a null birth date by design — so omitting the column
     * here would not fail loudly, it would silently delete a clinical guard.
     *
     * @var list<string>
     */
    private const IDENTITY_COLUMNS = [
        'id',
        'name',
        'medical_record_number',
        'branch_id',
        'is_active',
        'date_of_birth',
    ];

    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
    ) {}

    public function findSelectableById(?User $actor, int $patientId): ?Patient
    {
        if ($patientId < 1) {
            return null;
        }

        return $this->baseQuery($actor)->whereKey($patientId)->first();
    }

    public function searchByMedicalRecordNumber(?User $actor, string $medicalRecordNumber, int $limit): Collection
    {
        $needle = trim($medicalRecordNumber);

        if ($needle === '') {
            return collect();
        }

        // Exact first: a full Nomor RM must never be widened into a suffix scan.
        //
        // REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1 — "exact" spans every
        // spelling of the SAME number. This is the surface an operator uses while
        // holding a paper odontogram chart, and the branch code printed on an old
        // chart may be the deprecated one. Matching the literal string alone would
        // report the patient as not existing for a number the clinic itself issued.
        // Only the branch-code segment varies (the variants are parsed and
        // recomposed, never string-replaced), so this stays an exact match on the
        // year and the manual sequence and never becomes a suffix scan.
        $exact = $this->baseQuery($actor)
            ->whereIn('medical_record_number', $this->medicalRecordNumbers->equivalentNumbers($needle))
            ->limit(max(1, $limit))
            ->get();

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        if (mb_strlen($needle) < CrossBranchPatientLookupService::MIN_SUFFIX_LENGTH) {
            return collect();
        }

        return $this->baseQuery($actor)
            ->where('medical_record_number', 'LIKE', '%'.$this->escapeLike($needle))
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return Builder<Patient>
     */
    private function baseQuery(?User $actor): Builder
    {
        $query = Patient::query()
            ->select(self::IDENTITY_COLUMNS)
            ->with('branch:id,code,name');

        if ($actor !== null) {
            $query = $this->doctorScope->applyPatientScopeForUser($actor, $query);
        }

        return $query;
    }

    /**
     * Escape LIKE metacharacters so operator input is matched literally.
     *
     * Copied in behaviour from CrossBranchPatientLookupService: backslash
     * escaping WITHOUT an explicit `ESCAPE` clause. Adding one has been proven
     * on this codebase to corrupt PDO placeholder binding on PostgreSQL under
     * PHP 8.3 — the production runtime — so the default escape character is
     * relied on deliberately.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
