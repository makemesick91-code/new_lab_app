<?php

declare(strict_types=1);

namespace App\Support\Legacy;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — THE authority that
 * turns a submitted visit id into a trusted binding, or refuses.
 *
 * ONE RULE, ONE HOME. Both legacy archives (RME and Odontogram) and every
 * surface that reaches them — HTTP, a future CLI, a direct service call — ask
 * this class. Nothing else may resolve a visit for a legacy import, because a
 * second implementation is a second answer, and the two would drift.
 *
 * IT READS. IT NEVER WRITES. This class creates nothing, updates nothing and
 * dispatches nothing. In particular it can NEVER create a visit: point-of-visit
 * migration means "a real encounter already happened", and a path that could
 * manufacture the encounter it claims to be evidence of would be worthless.
 * The repository methods it calls are read-only lookups.
 *
 * TWO INDEPENDENT GATES, ON PURPOSE.
 *
 *   1. REPOSITORY SCOPE — `findByIdInBranches()` with the actor's server-resolved
 *      RME working branch ids. An id from the request can never reach a row
 *      outside that set, so the query itself is the first boundary.
 *   2. POLICY — `ClinicVisitPolicy::view()`, which re-checks the permission AND
 *      the branch scope through RmeWorkingBranchScope and fails closed when the
 *      actor has no valid working context.
 *
 * Either alone would be defensible; both is the pattern the legacy module
 * already uses (scoped repository + policy), and it means a mistake in one
 * layer is not a breach.
 *
 * ONE REFUSAL CODE FOR THREE CAUSES. Missing, soft-deleted and not-yours all
 * answer VISIT_NOT_ACCESSIBLE with identical prose. Distinguishing them would
 * turn this endpoint into an enumeration oracle over the visit table for any
 * account that can reach it.
 *
 * THE BRANCH IS NOT DECIDED HERE. See LegacyVisitAttestation: the archive's
 * `origin_branch_id` stays RM-derived (FIX-ROLL2-1). This class decides
 * IDENTITY, the DATE CEILING and ACCESS only.
 */
class LegacyVisitBindingService
{
    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly RmeWorkingBranchScope $workingScope,
    ) {}

    /**
     * Resolve a submitted visit id into an accepted attestation, or refuse.
     *
     * `$submittedPatientId` is accepted ONLY so that a disagreement can be
     * rejected explicitly instead of silently ignored — exactly how
     * `origin_branch_id` is treated on the ordinary intake path. It is never
     * the source of the answer: the visit's own patient is.
     *
     * @throws LegacyVisitBindingRefusal
     */
    public function resolve(
        ?int $visitId,
        User $actor,
        bool $dateAttested,
        ?int $submittedPatientId = null,
    ): LegacyVisitAttestation {
        if ($visitId === null || $visitId <= 0) {
            throw LegacyVisitBindingRefusal::visitRequired();
        }

        // GATE 1 — the query itself is scoped. An empty scope yields an empty
        // IN () and therefore no row, which is the correct fail-closed answer
        // for an account with no valid working branch context.
        $branchIds = $this->workingScope->branchIdsFor($actor);

        $visit = $branchIds === []
            ? null
            : $this->visits->findByIdInBranches($branchIds, $visitId);

        if ($visit === null) {
            throw LegacyVisitBindingRefusal::visitNotAccessible();
        }

        // GATE 2 — the canonical visit policy, independent of the query above.
        if (! Gate::forUser($actor)->allows('view', $visit)) {
            throw LegacyVisitBindingRefusal::visitNotAccessible();
        }

        $this->assertVisitCanAnchorAttestation($visit);

        if ($submittedPatientId !== null && $submittedPatientId !== (int) $visit->patient_id) {
            throw LegacyVisitBindingRefusal::patientMismatch();
        }

        // The attestation is a human statement. A path that accepted the
        // upload without it would be recording a verification that never
        // happened, which is worse than having no evidence at all.
        if (! $dateAttested) {
            throw LegacyVisitBindingRefusal::attestationRequired();
        }

        $patient = $visit->patient;

        if ($patient === null) {
            throw LegacyVisitBindingRefusal::visitInvalid(
                'Kunjungan ini tidak terhubung ke data pasien yang valid.',
            );
        }

        return new LegacyVisitAttestation(
            visit: $visit,
            patient: $patient,
            visitDate: $this->visitDate($visit),
            verifiedBy: $actor,
        );
    }

    /**
     * REVALIDATION — is the attestation on this staging row still true?
     *
     * Called before finalization freezes a permanent clinical record. Between
     * upload and publish the visit can be cancelled, soft-deleted or
     * rescheduled, so the upload-time snapshot must never be the authority for
     * the write.
     *
     * DRIFT IS A REFUSAL, NOT AN UPDATE. If the visit's date has moved, this
     * does NOT quietly adopt the new ceiling — the human attested against the
     * old one, and silently re-pointing their statement at a different bound
     * would make the evidence a lie. The operator goes back through the
     * canonical correction path.
     *
     * @param  int|null  $visitId  the visit recorded on the staging row
     * @param  string|null  $attestedVisitDate  the ceiling recorded at attestation time
     *
     * @throws LegacyVisitBindingRefusal
     */
    public function revalidate(?int $visitId, ?string $attestedVisitDate): ClinicVisit
    {
        if ($visitId === null || $visitId <= 0) {
            throw LegacyVisitBindingRefusal::visitRequired();
        }

        // DELIBERATELY UNSCOPED, AND SAFE. Revalidation is a SYSTEM integrity
        // check on evidence already stored on a row the caller has ALREADY been
        // authorized to act on (the import's own policy and branch scope ran
        // first). The question here is "is this stored attestation still
        // lawful?", not "may this person see that visit" — and re-scoping to
        // the CHECKER's branches would refuse a perfectly valid attestation
        // whenever the checker works in a different branch from the uploader,
        // which is the normal maker-checker arrangement. It reads one row by
        // primary key and returns no data to the caller beyond the verdict.
        $visit = ClinicVisit::query()->whereKey($visitId)->first();

        if ($visit === null) {
            throw LegacyVisitBindingRefusal::visitInvalid(
                'Kunjungan yang menjadi dasar verifikasi tanggal sudah tidak tersedia.',
            );
        }

        $this->assertVisitCanAnchorAttestation($visit);

        if ($attestedVisitDate !== null) {
            $current = $this->visitDate($visit)->toDateString();

            if ($current !== CarbonImmutable::parse($attestedVisitDate)->toDateString()) {
                throw LegacyVisitBindingRefusal::visitInvalid(sprintf(
                    'Tanggal kunjungan berubah (%s menjadi %s) setelah verifikasi tanggal. '
                    .'Batalkan dan impor ulang dokumen ini melalui proses koreksi.',
                    CarbonImmutable::parse($attestedVisitDate)->format('d-m-Y'),
                    $this->visitDate($visit)->format('d-m-Y'),
                ));
            }
        }

        return $visit;
    }

    /**
     * A visit may anchor an attestation only if it is a real, live encounter
     * with a real calendar date.
     *
     * CANCELLED IS REFUSED because a cancelled visit is the record of an
     * encounter that did NOT happen, so it cannot evidence that a clinician
     * was holding the patient's old chart. Soft-deleted rows never reach here
     * — the repository excludes them.
     *
     * @throws LegacyVisitBindingRefusal
     */
    private function assertVisitCanAnchorAttestation(ClinicVisit $visit): void
    {
        if ($visit->trashed()) {
            throw LegacyVisitBindingRefusal::visitNotAccessible();
        }

        if ($visit->status === ClinicVisit::STATUS_CANCELLED) {
            throw LegacyVisitBindingRefusal::visitInvalid(
                'Kunjungan yang dibatalkan tidak dapat menjadi dasar verifikasi arsip legacy.',
            );
        }

        if ($visit->visit_date === null) {
            throw LegacyVisitBindingRefusal::visitInvalid(
                'Kunjungan ini tidak memiliki tanggal kunjungan yang valid.',
            );
        }
    }

    /**
     * The visit's calendar date as an immutable date-only value.
     *
     * `visit_date` is cast to `date` on the model and is treated as an opaque
     * calendar date throughout the clinical domain — never shifted between
     * timezones. LEGACY-RME-DATE-TZ-1 made that distinction load-bearing.
     */
    private function visitDate(ClinicVisit $visit): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $visit->visit_date instanceof \DateTimeInterface
                ? $visit->visit_date->format('Y-m-d')
                : (string) $visit->visit_date
        )->startOfDay();
    }
}
