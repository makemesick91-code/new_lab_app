<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeTimelineEntry;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * LEGACY-RME-PDF-1C — read-only projection of a patient's RME history with the
 * published legacy archive folded in.
 *
 * DOMAIN BOUNDARY. Legacy and native remain two different entities. Nothing
 * here converts a legacy record into a ClinicVisit or a MedicalRecord, and
 * nothing writes: this service only reads and merges for one screen.
 *
 * WHAT APPEARS. ONLY records in status PUBLISHED. A staged import — draft,
 * queued, processing, ready-for-review, reviewed, failed or cancelled — is work
 * in progress and must never look like part of the patient's medical history.
 * A VOIDed record is likewise excluded (the repository's published-only finder
 * enforces both).
 *
 * ORDERING. By the CLINICAL date (`rme_date` for legacy, `visit_date` for
 * native), never by upload or creation time: an archive uploaded today is a
 * document from years ago and must sort where it clinically belongs.
 *
 * ACCESS. Branch scope is resolved server-side from the caller
 * (LegacyRmeWorkspaceScope) and the archive is behind its feature flag; with
 * the flag off or the permission missing the legacy side is simply empty, so
 * the native history renders exactly as it did before this sprint.
 */
class LegacyRmePatientHistoryService
{
    public function __construct(
        private readonly LegacyRmeRecordRepositoryInterface $records,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly DoctorPatientScopeService $doctorScope,
    ) {}

    /**
     * Published legacy records this user may see for a patient, oldest first.
     *
     * @return Collection<int, LegacyRmeRecord>
     */
    public function publishedRecordsFor(?User $user, int $patientId): Collection
    {
        // LEGACY-RME-PDF-1D — the intake operator OR a clinical reader (doctor).
        // The permission set is taken from the policy so the timeline and the
        // viewer can never disagree about who may read an archive.
        if ($user === null || ! $this->feature->enabled() || ! $user->canAny(LegacyRmeRecordPolicy::READ_PERMISSIONS)) {
            return collect();
        }

        // LEGACY-RME-PDF-ROLL-2 pilot finding: the timeline must not be a
        // weaker door than the viewer policy. A doctor reaches a patient only
        // through a real clinical relationship, so the archive of a patient
        // they are not treating is not theirs to list either — branch scope
        // alone would make the legacy history broader than the native one.
        if ($this->doctorScope->shouldApplyDoctorScope($user)) {
            $patient = Patient::query()->find($patientId);

            if (! $patient instanceof Patient || ! $this->doctorScope->doctorCanAccessPatient($user, $patient)) {
                return collect();
            }
        }

        return $this->records->listPublishedForPatientInBranches(
            $this->scope->branchIdsFor($user),
            $patientId,
            $this->scope->includesUnscopedRowsFor($user),
        );
    }

    /**
     * Merge the caller's already-resolved native visit history with the
     * patient's published legacy archive into one chronological timeline.
     *
     * The native side is passed in rather than re-queried: the RME workspace
     * already resolved it under its own authorization, and re-deriving it here
     * would create a second, divergent definition of "the patient's visits".
     *
     * Returns an EMPTY collection when the patient has no visible legacy
     * archive. A merged view with nothing to merge would only restate the
     * native visit history the workspace already shows, so the caller renders
     * nothing at all — which is also exactly what happens while the feature
     * flag is off.
     *
     * @param  iterable<int, ClinicVisit>  $nativeVisits
     * @return Collection<int, LegacyRmeTimelineEntry>
     */
    public function timelineFor(?User $user, int $patientId, iterable $nativeVisits): Collection
    {
        $legacyRecords = $this->publishedRecordsFor($user, $patientId);

        if ($legacyRecords->isEmpty()) {
            return collect();
        }

        $entries = collect();

        foreach ($nativeVisits as $visit) {
            $entries->push(LegacyRmeTimelineEntry::native(
                $visit->visit_date,
                'RME DaengtisiaMS',
                is_string($visit->visit_number) ? $visit->visit_number : null,
                $visit->doctor?->name,
                $this->nativeUrl($visit),
                (int) $visit->getKey(),
            ));
        }

        foreach ($legacyRecords as $record) {
            $entries->push(LegacyRmeTimelineEntry::legacy(
                $record->rme_date,
                'RME Lama (Arsip)',
                $this->legacyDetail($record),
                $this->legacyUrl($record),
                (int) $record->getKey(),
            ));
        }

        return $entries
            ->sortBy(fn (LegacyRmeTimelineEntry $entry): string => $entry->sortKey())
            ->values();
    }

    /**
     * Whether this user has a legacy archive worth showing at all — used to
     * keep the timeline card out of the way entirely when the sprint's
     * capability is off or the patient simply has no archive.
     */
    public function hasLegacyHistory(?User $user, int $patientId): bool
    {
        return $this->publishedRecordsFor($user, $patientId)->isNotEmpty();
    }

    private function legacyDetail(LegacyRmeRecord $record): string
    {
        $pages = (int) ($record->page_count ?? 0);

        return $pages > 0 ? $pages.' halaman' : 'Dokumen arsip';
    }

    /**
     * `Route::has` guards keep this projection safe to render even where a
     * route is intentionally absent (a disabled capability, a trimmed route
     * cache); a missing link degrades to plain text rather than a 500.
     */
    private function legacyUrl(LegacyRmeRecord $record): ?string
    {
        return Route::has('rme.legacy-records.show')
            ? route('rme.legacy-records.show', $record->getKey())
            : null;
    }

    private function nativeUrl(ClinicVisit $visit): ?string
    {
        return Route::has('rme.visits.show')
            ? route('rme.visits.show', $visit->getKey())
            : null;
    }
}
