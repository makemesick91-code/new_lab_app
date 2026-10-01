<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemVerdict;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadPatientResolution;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyImport\Services\LegacyImportDailyQuotaService;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePatientLookupService;
use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
use App\Modules\LegacyRme\Services\LegacyRmeSourceRmNormalizer;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;

/**
 * Mass adapter for Legacy RME — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * Every rule below is READ from a canonical service, never restated. The date
 * bounds come from LegacyRmeDateRuleService (which owns the native-RME
 * boundary, the birth bound and the future bound), the branch from
 * LegacyRmeBranchResolver plus LegacyRmeBranchAdmissionService, the slot from
 * LegacySingleActiveDocumentService, and the ceiling from the hub quota.
 *
 * THE MANIFEST RM IS THE DOCUMENT'S OWN CLAIM
 * -------------------------------------------
 * `medical_record_number` is fed to createFromUpload() as `sourceRm` — the RM
 * printed on the paper — not merely as a lookup key. That is what keeps
 * SOURCE-RM-BINDING-1 doing real work in a mass batch: the server independently
 * resolves that claim and refuses unless it names the same patient the lookup
 * produced. In single upload a human asserts this by ticking a box; here the
 * server asserts it for every row, which is the stronger of the two.
 *
 * origin_branch_id is passed as NULL on purpose. The canonical service resolves
 * the branch from the patient itself, and a manifest is forbidden from naming
 * one (§10) — a file an operator edits must never be able to move a document
 * into another branch's scope.
 */
class LegacyRmeMassUploadAdapter implements LegacyMassUploadAdapter
{
    public function __construct(
        private readonly LegacyRmeImportService $imports,
        private readonly LegacyRmePatientLookupService $patients,
        private readonly LegacyRmeDateRuleService $dateRules,
        private readonly LegacyRmeSourcePatientBindingService $binding,
        private readonly LegacyRmeSourceRmNormalizer $normalizer,
        private readonly LegacyRmeBranchResolver $branchResolver,
        private readonly LegacyRmeBranchAdmissionService $admission,
        private readonly LegacySingleActiveDocumentService $slots,
        private readonly LegacyImportDailyQuotaService $quota,
    ) {}

    public function importType(): string
    {
        return LegacyImportType::LEGACY_RME;
    }

    public function resolvePatient(?User $actor, string $medicalRecordNumber): LegacyMassUploadPatientResolution
    {
        $normalized = trim($medicalRecordNumber);

        if ($normalized === '') {
            return LegacyMassUploadPatientResolution::missingIdentifier();
        }

        $payload = $this->patients->search($normalized);
        $results = (array) ($payload['results'] ?? []);

        if ($results === []) {
            return LegacyMassUploadPatientResolution::notFound();
        }

        if (count($results) > 1) {
            // Several distinct RMs matched the query. A mass tool must never
            // choose between them.
            return LegacyMassUploadPatientResolution::ambiguous(count($results));
        }

        $first = (array) ($results[array_key_first($results)] ?? []);
        $patientId = $first['id'] ?? null;

        // The lookup service deliberately leaves `id` null when one RM maps to
        // more than one patient row. That is a duplicate-RM data problem for a
        // human to settle, not something to guess at.
        if (! is_int($patientId) || $patientId <= 0) {
            return LegacyMassUploadPatientResolution::ambiguous(1);
        }

        // Scope check. The lookup itself is intentionally global (patient
        // identity is global in this system); authorization is applied here.
        $patient = $this->patients->findSelectable($actor, $patientId);

        if ($patient === null) {
            return LegacyMassUploadPatientResolution::notAuthorized();
        }

        return LegacyMassUploadPatientResolution::found($patient);
    }

    public function evaluateRow(
        Patient $patient,
        LegacyMassUploadManifestRow $row,
        ?User $actor,
    ): LegacyMassUploadItemVerdict {
        $normalizedRm = $this->normalizer->normalize($row->medicalRecordNumber);

        if ($normalizedRm === null || $normalizedRm === '') {
            return LegacyMassUploadItemVerdict::blocked(LegacyMassUploadReason::SOURCE_RM_MISSING);
        }

        // 1. The document's own claim about whose record it is. First, because
        //    it is the wrong-patient gate and nothing else matters if it fails.
        $bindingResult = $this->binding->bind($normalizedRm, $patient);

        if ($bindingResult->failed()) {
            // The binding runs its OWN exact resolution, independent of the
            // patient lookup above (which falls back to a suffix match). So
            // this is not a tautology: a partial manifest RM can suffix-match a
            // patient here and still be refused as not-a-valid-number, which is
            // a completely different instruction for the operator.
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::fromSourceRmFailure($bindingResult->code),
                $bindingResult->message,
                null,
                $normalizedRm,
            );
        }

        // 2. Dates. The canonical service owns every bound; we only translate
        //    its code into our reporting vocabulary.
        if ($row->selectedDate === null || $row->selectedDate === '') {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::DATE_MISSING,
                null,
                null,
                $normalizedRm,
            );
        }

        $dateResult = $this->dateRules->evaluate($patient, $row->selectedDate, $row->latestDate);

        if ($dateResult->failed()) {
            return LegacyMassUploadItemVerdict::blocked(
                $this->mapDateCode($dateResult->code),
                // The domain wrote this message for an operator; it is more
                // specific than our canned text, so it wins.
                $dateResult->message,
                null,
                $normalizedRm,
            );
        }

        // 3. Branch, resolved from the patient — never from the manifest.
        $branch = $this->branchResolver->resolveForPatient($patient, $actor);

        if (! $branch->resolved) {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::BRANCH_MISMATCH,
                $branch->message,
                null,
                $normalizedRm,
            );
        }

        $decision = $this->admission->decide($branch);

        if ($decision->denied()) {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::BRANCH_NOT_ADMITTED,
                $decision->message,
                $branch->branchId,
                $normalizedRm,
            );
        }

        // 4. One active legacy RME lifecycle per patient. Null means the slot
        //    is free; a non-null occupancy carries its own code, which is
        //    already our vocabulary.
        $occupancy = $this->slots->previewForNewLifecycle($this->importType(), (int) $patient->getKey());

        if ($occupancy !== null) {
            return LegacyMassUploadItemVerdict::blocked(
                $this->mapOccupancyCode($occupancy->code()),
                $occupancy->message(),
                $branch->branchId,
                $normalizedRm,
            );
        }

        // 5. The canonical daily ceiling. Currently unlimited (null); honoured
        //    anyway so a future declared limit surfaces in preflight rather
        //    than mid-dispatch. Mass upload adds no cap of its own.
        $denial = $this->quota->preview($this->importType(), (int) $branch->branchId);

        if (is_string($denial) && $denial !== '') {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::CAPACITY_EXCEEDED,
                $denial,
                $branch->branchId,
                $normalizedRm,
            );
        }

        // An RM that only matched after normalisation is fine, but the operator
        // should see that their spreadsheet and the document disagree
        // cosmetically — that is often the first sign of a deeper mix-up.
        if ($normalizedRm !== trim($row->medicalRecordNumber)) {
            return LegacyMassUploadItemVerdict::warning(
                LegacyMassUploadReason::SOURCE_RM_MISMATCH,
                'Nomor RM pada manifest cocok setelah normalisasi format. Pastikan sesuai dokumen.',
                $branch->branchId,
                $normalizedRm,
            );
        }

        return LegacyMassUploadItemVerdict::eligible($branch->branchId, $normalizedRm);
    }

    public function create(
        Patient $patient,
        LegacyMassUploadManifestRow $row,
        UploadedFile $document,
        User $actor,
    ): int {
        // The canonical call. Everything re-decided here under the slot lock:
        // binding, dates, branch, admission, quota reservation, slot, PDF
        // validation, storage and the render dispatch. A stale preflight
        // becomes a ValidationException the dispatcher records as BLOCKED.
        $import = $this->imports->createFromUpload(
            patient: $patient,
            selectedRmeDate: (string) $row->selectedDate,
            sourceRm: $row->medicalRecordNumber,
            // Never from the manifest (§10). The service resolves it.
            originBranchId: null,
            document: $document,
            actor: $actor,
            latestRmeDate: $row->latestDate,
            // Backlog pathway. Mass migration must not fabricate a visit to
            // unlock ingestion (§31); the visit-bound path stays a separate,
            // point-of-visit surface with its own attestation.
            attestation: null,
        );

        return (int) $import->getKey();
    }

    public function assignCreatedImport(LegacyMassUploadItem $item, int $importId): void
    {
        $item->rme_legacy_import_id = $importId;
    }

    public function quotaRemainingToday(int $branchId): ?int
    {
        return $this->quota->remainingToday($this->importType(), $branchId);
    }

    /**
     * Translate a canonical date-rule code into our reporting vocabulary.
     *
     * Unmapped codes fall through to DATE_INVALID rather than being echoed.
     * The date service may gain codes; inventing a reason string from an
     * unknown one would put a code we do not understand in front of an
     * operator as though we did.
     */
    private function mapDateCode(?string $code): string
    {
        return match ($code) {
            LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME => LegacyMassUploadReason::NATIVE_RME_BOUNDARY,
            LegacyRmeDateRuleService::CODE_LEGACY_DATE_RANGE_INVALID => LegacyMassUploadReason::DATE_RANGE_INVALID,
            LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE,
            LegacyRmeDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
            LegacyRmeDateRuleService::CODE_LEGACY_DATE_INVALID => LegacyMassUploadReason::DATE_INVALID,
            default => LegacyMassUploadReason::DATE_INVALID,
        };
    }

    private function mapOccupancyCode(?string $code): string
    {
        return match ($code) {
            'ALREADY_PUBLISHED' => LegacyMassUploadReason::ALREADY_PUBLISHED,
            'ACTIVE_IMPORT_EXISTS' => LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS,
            default => LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS,
        };
    }
}
