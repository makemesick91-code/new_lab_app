<?php

namespace App\Modules\ClinicOversight\Services;

use App\Models\User;
use App\Modules\ClinicOversight\Interfaces\OversightClinicalRecordRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitPrintDataBuilder;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\UnifiedMedicalRecordIndexRepositoryInterface;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordScope;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Services\OdontogramPrintFormatter;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — read-only access to native RME
 * (patient RM book, visit sheets, odontogram, prescription) and PUBLISHED
 * legacy archives, across every RME branch, for the oversight roles.
 *
 * REUSE, NOT A SECOND ENGINE. The patient index is the canonical unified
 * medical-record index (rule 173): its repository takes an explicit scope
 * VALUE OBJECT, so this service hands it the oversight branch set instead of
 * re-implementing the eligibility predicate. Legacy lists come from the legacy
 * record repositories' own branch-scoped readers; the native print data comes
 * from the shared ClinicVisitPrintDataBuilder.
 *
 * WHAT IT NEVER DOES: write a row, create a draft, mark a prescription
 * printed, resolve a "canonical" record by writing to it, or touch an online
 * context. It also never reads a consent record, a KTP/NIK, or a patient
 * document — those stay behind their own permissions.
 */
class OversightClinicalRecordService
{
    public function __construct(
        private readonly ClinicOversightScope $scope,
        private readonly OversightClinicalRecordRepositoryInterface $records,
        private readonly UnifiedMedicalRecordIndexRepositoryInterface $unifiedIndex,
        private readonly LegacyRmeRecordRepositoryInterface $legacyRme,
        private readonly LegacyOdontogramRecordRepositoryInterface $legacyOdontogram,
        private readonly ClinicVisitPrintDataBuilder $printData,
        private readonly OdontogramPrintFormatter $odontogramFormatter,
        private readonly PatientMedicalRecordNumberService $rmNumbers,
    ) {}

    /**
     * @return list<int>
     */
    public function branchIdsFor(User $user, mixed $requestedBranch = null): array
    {
        return $this->scope->narrow(
            $this->scope->branchIdsFor($user, ClinicOversightScope::PERMISSION_CLINICAL),
            $requestedBranch,
        );
    }

    /**
     * The canonical unified-index scope, built from the OVERSIGHT branch set:
     * native and both legacy legs read the same explicit branches, rows with
     * no branch are never included, and no doctor patient-scope applies (these
     * roles are not doctors).
     *
     * @param  list<int>  $branchIds
     */
    public function unifiedScope(array $branchIds): UnifiedMedicalRecordScope
    {
        return new UnifiedMedicalRecordScope(
            nativeBranchIds: $branchIds,
            legacyRmeReadable: $branchIds !== [],
            legacyRmeBranchIds: $branchIds,
            legacyRmeIncludesUnscoped: false,
            legacyOdontogramReadable: $branchIds !== [],
            legacyOdontogramBranchIds: $branchIds,
            legacyOdontogramIncludesUnscoped: false,
            patientScope: null,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{patients: LengthAwarePaginator, summary: array<string, int>, latestNative: Collection}
     */
    public function index(User $user, array $filters, int $perPage = 20): array
    {
        $scope = $this->unifiedScope($this->branchIdsFor($user, $filters['branch_id'] ?? null));
        $filters['source'] = UnifiedMedicalRecordSource::normalize($filters['source'] ?? null);

        $patients = $this->unifiedIndex->paginatePatients($scope, $filters, $perPage);
        $ids = collect($patients->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'patients' => $patients,
            'summary' => $this->unifiedIndex->summarize($scope, $filters),
            'latestNative' => $this->unifiedIndex->latestNativeRecordsFor($scope, $ids),
        ];
    }

    /**
     * Bounded, unpaginated rows for the CSV export of the index.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Patient>
     */
    public function indexForExport(User $user, array $filters, int $limit): Collection
    {
        $scope = $this->unifiedScope($this->branchIdsFor($user, $filters['branch_id'] ?? null));
        $filters['source'] = UnifiedMedicalRecordSource::normalize($filters['source'] ?? null);

        return collect($this->unifiedIndex->paginatePatients($scope, $filters, $limit)->items());
    }

    /**
     * The patient history (native visits + RM book + published legacy
     * archives). Null when the patient has no record in the oversight scope —
     * the caller answers 404 so existence is never confirmed.
     *
     * @return array<string, mixed>|null
     */
    public function patientHistory(User $user, int $patientId): ?array
    {
        $branchIds = $this->branchIdsFor($user);
        $patient = $this->unifiedIndex->findVisiblePatient($this->unifiedScope($branchIds), $patientId);

        if ($patient === null) {
            return null;
        }

        $sheets = $this->records->sheetsForPatient($branchIds, (int) $patient->id);

        return [
            'patient' => $patient,
            'visits' => $this->records->visitsForPatient($branchIds, (int) $patient->id),
            'sheets' => $sheets,
            'handwritingBook' => $this->handwritingBook($sheets),
            'legacyRme' => $this->legacyRme->listPublishedForPatientInBranches($branchIds, (int) $patient->id, false)->load('originBranch'),
            'legacyOdontogram' => $this->legacyOdontogram->listPublishedForPatientInBranches($branchIds, (int) $patient->id, false)->load('branch'),
        ];
    }

    /**
     * "Riwayat RME Pasien" lookup: a Nomor RM (any accepted spelling of its
     * branch code) resolves to a patient only when that patient is visible in
     * the oversight scope. Exact match only — never a fuzzy or partial hit.
     */
    public function findPatientByMedicalRecordNumber(User $user, string $number): ?Patient
    {
        $scope = $this->unifiedScope($this->branchIdsFor($user));

        foreach ($this->records->patientIdsByMedicalRecordNumbers($this->rmNumbers->equivalentNumbers($number)) as $id) {
            $patient = $this->unifiedIndex->findVisiblePatient($scope, $id);

            if ($patient !== null) {
                return $patient;
            }
        }

        return null;
    }

    public function findVisit(User $user, int $visitId): ?ClinicVisit
    {
        return $this->records->findVisitInBranches($this->branchIdsFor($user), $visitId);
    }

    public function findVisitByNumber(User $user, string $visitNumber): ?ClinicVisit
    {
        return $this->records->findVisitByNumberInBranches($this->branchIdsFor($user), $visitNumber);
    }

    /**
     * Everything the read-only visit detail renders.
     *
     * @return array<string, mixed>
     */
    public function visitDetail(ClinicVisit $visit): array
    {
        $visit->load([
            'patient',
            'branch',
            'doctor',
            'clinicRoom',
            'initialTreatment',
            'medicalRecord.diagnoses.clinicalDiagnosis',
            'medicalRecord.handwritingPages',
            'medicalRecord.finalizedBy',
            'odontogram.finalizer',
            'rmePrescription.doctor',
        ]);

        $odontogram = $visit->odontogram;
        $structuredOdontogram = $odontogram instanceof Odontogram && $odontogram->exists
            ? $this->odontogramFormatter->format($odontogram)
            : null;

        $invoice = RmeInvoice::query()
            ->where('clinic_visit_id', $visit->id)
            ->whereNull('deleted_at')
            ->with(['items.treatment', 'payments.paymentMethod'])
            ->latest('id')
            ->first();

        return [
            'visit' => $visit,
            'medicalRecord' => $visit->medicalRecord,
            'handwritingBook' => $visit->medicalRecord !== null
                ? $this->handwritingBook(collect([$visit->medicalRecord]))
                : collect(),
            'odontogram' => $odontogram,
            'structuredOdontogram' => $structuredOdontogram,
            'prescription' => $visit->rmePrescription,
            'invoice' => $invoice,
        ];
    }

    /**
     * The native RME print data — the SAME builder the operational print uses.
     *
     * @return array<string, mixed>
     */
    public function printData(ClinicVisit $visit): array
    {
        return $this->printData->build($visit);
    }

    /**
     * Structured odontogram print data, or null when the visit has no SAVED
     * chart (an unsaved placeholder is never rendered as a clinical record).
     *
     * @return array{odontogram: Odontogram, clinicVisit: ClinicVisit, structured: array<string, mixed>}|null
     */
    public function odontogramPrintData(ClinicVisit $visit): ?array
    {
        $odontogram = Odontogram::query()
            ->where('clinic_visit_id', $visit->id)
            ->with(['clinicVisit.patient', 'clinicVisit.doctor', 'clinicVisit.branch', 'finalizer'])
            ->first();

        if ($odontogram === null) {
            return null;
        }

        return [
            'odontogram' => $odontogram,
            'clinicVisit' => $odontogram->clinicVisit,
            'structured' => $this->odontogramFormatter->format($odontogram),
        ];
    }

    /**
     * The handwriting pages of the given sheets, in book order. Each entry
     * names the row that holds the image so the oversight image route can
     * stream it after re-authorizing the parent record's branch.
     *
     * @param  Collection<int, MedicalRecord>  $sheets
     * @return Collection<int, array<string, mixed>>
     */
    private function handwritingBook(Collection $sheets): Collection
    {
        $book = collect();
        $position = 1;

        foreach ($sheets as $sheet) {
            $legacy = $sheet->latestHandwriting();

            if ($legacy !== null && filled($legacy->handwriting_path)) {
                $book->push([
                    'position' => $position++,
                    'medical_record_id' => (int) $sheet->id,
                    'clinic_visit_id' => (int) $sheet->clinic_visit_id,
                    'visit_number' => $sheet->clinicVisit?->visit_number,
                    'visit_date' => $sheet->clinicVisit?->visit_date,
                    'kind' => 'handwriting',
                    'id' => (int) $legacy->id,
                    'page_number' => 1,
                    'saved_at' => $legacy->saved_at,
                ]);
            }

            foreach ($sheet->handwritingPages as $page) {
                if (! filled($page->handwriting_path)) {
                    continue;
                }

                $book->push([
                    'position' => $position++,
                    'medical_record_id' => (int) $sheet->id,
                    'clinic_visit_id' => (int) $sheet->clinic_visit_id,
                    'visit_number' => $sheet->clinicVisit?->visit_number,
                    'visit_date' => $sheet->clinicVisit?->visit_date,
                    'kind' => 'page',
                    'id' => (int) $page->id,
                    'page_number' => (int) $page->page_number,
                    'saved_at' => $page->saved_at,
                ]);
            }
        }

        return $book;
    }
}
