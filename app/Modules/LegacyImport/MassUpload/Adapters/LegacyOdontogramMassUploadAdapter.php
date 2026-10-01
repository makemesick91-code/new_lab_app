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
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramBranchBindingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
use App\Modules\LegacyRme\Services\LegacyRmeSourceRmNormalizer;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Mass adapter for Legacy Odontogram — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * DELIBERATELY NOT A MIRROR OF THE RME ADAPTER (§33)
 * --------------------------------------------------
 * The odontogram domain is genuinely different and this class does not pretend
 * otherwise:
 *
 *   - ONE date, not a range. There is no `latest` bound to declare, so the
 *     manifest column is `document_date` and `latestDate` is always null.
 *   - NO wave/admission service. LegacyOdontogramImportService does not consult
 *     one, so neither does this adapter. Inventing an admission check here
 *     would be a rule that exists only in the mass path — the exact drift §6
 *     forbids.
 *   - NO origin-branch parameter on createFromUpload(). The service resolves
 *     the branch internally via its own binding service.
 *
 * THE ONE PLACE THIS ADAPTER IS STRICTER THAN SINGLE UPLOAD
 * --------------------------------------------------------
 * Single odontogram upload has no source-RM concept at all. Its only
 * wrong-patient defence is the human `patient_confirmation` checkbox — and mass
 * upload, by its nature, removes that per-document human act.
 *
 * Shipping mass odontogram with nothing in its place would have left the path
 * with NO wrong-patient defence, which is precisely the exposure that
 * SOURCE-RM-BINDING-1 was built to close on the RME side after a real
 * Wave-2 wrong-patient binding.
 *
 * So the manifest RM is asserted against the resolved patient by the same
 * canonical binding service the RME path uses, and any mismatch HARD BLOCKS the
 * row. This makes mass odontogram strictly safer than single odontogram upload,
 * which is the correct direction for a bulk tool. It is enforced here in the
 * adapter because LegacyOdontogramImportService takes no sourceRm argument and
 * this sprint does not change that signature — the gate belongs to the mass
 * pathway that needs it, not bolted onto a clinical service used elsewhere.
 */
class LegacyOdontogramMassUploadAdapter implements LegacyMassUploadAdapter
{
    /** Bounded lookup: an RM that matches more than a couple of rows is ambiguous anyway. */
    private const LOOKUP_LIMIT = 5;

    public function __construct(
        private readonly LegacyOdontogramImportService $imports,
        private readonly LegacyOdontogramPatientRepositoryInterface $patients,
        private readonly LegacyOdontogramDateRuleService $dateRules,
        private readonly LegacyOdontogramBranchBindingService $branchBinding,
        private readonly LegacySingleActiveDocumentService $slots,
        private readonly LegacyImportDailyQuotaService $quota,
        // Borrowed from the RME module on purpose. bind() takes only
        // (?string $sourceRm, Patient $selectedPatient) and carries no RME
        // coupling, so reusing it keeps ONE implementation of the
        // wrong-patient assertion rather than a second one that can drift.
        private readonly LegacyRmeSourcePatientBindingService $binding,
        private readonly LegacyRmeSourceRmNormalizer $normalizer,
    ) {}

    public function importType(): string
    {
        return LegacyImportType::LEGACY_ODONTOGRAM;
    }

    public function resolvePatient(?User $actor, string $medicalRecordNumber): LegacyMassUploadPatientResolution
    {
        $normalized = trim($medicalRecordNumber);

        if ($normalized === '') {
            return LegacyMassUploadPatientResolution::missingIdentifier();
        }

        $matches = $this->patients->searchByMedicalRecordNumber($actor, $normalized, self::LOOKUP_LIMIT);

        if ($matches->isEmpty()) {
            return LegacyMassUploadPatientResolution::notFound();
        }

        if ($matches->count() > 1) {
            return LegacyMassUploadPatientResolution::ambiguous($matches->count());
        }

        $candidate = $matches->first();

        if (! $candidate instanceof Patient) {
            return LegacyMassUploadPatientResolution::notFound();
        }

        // Re-resolve through the scoped finder rather than trusting the search
        // result, so the authorization decision is made by the same method the
        // single-upload path uses.
        $patient = $this->patients->findSelectableById($actor, (int) $candidate->getKey());

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

        // 1. The wrong-patient gate this path did not previously have. First,
        //    and hard — see the class docblock for why it exists here.
        $bindingResult = $this->binding->bind($normalizedRm, $patient);

        if ($bindingResult->failed()) {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::fromSourceRmFailure($bindingResult->code),
                $bindingResult->message,
                null,
                $normalizedRm,
            );
        }

        // 2. Dates. One date only — there is no range to validate.
        if ($row->selectedDate === null || $row->selectedDate === '') {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::DATE_MISSING,
                null,
                null,
                $normalizedRm,
            );
        }

        $dateResult = $this->dateRules->evaluate($patient, $row->selectedDate);

        if ($dateResult->failed()) {
            return LegacyMassUploadItemVerdict::blocked(
                $this->mapDateCode($dateResult->code),
                $dateResult->message,
                null,
                $normalizedRm,
            );
        }

        // 3. Branch, resolved from the patient. No admission/wave step: the
        //    canonical odontogram service does not have one.
        $branch = $this->branchBinding->resolveForPatient($patient, $actor);

        if (! $branch->resolved) {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::BRANCH_MISMATCH,
                $branch->message,
                null,
                $normalizedRm,
            );
        }

        // 4. One active legacy ODONTOGRAM lifecycle per patient — evaluated
        //    entirely independently of the patient's RME slot (§14, §48). A
        //    published legacy RME must never block a first legacy chart, and
        //    passing the odontogram type here is what guarantees that.
        $occupancy = $this->slots->previewForNewLifecycle($this->importType(), (int) $patient->getKey());

        if ($occupancy !== null) {
            return LegacyMassUploadItemVerdict::blocked(
                $this->mapOccupancyCode($occupancy->code()),
                $occupancy->message(),
                $branch->branchId,
                $normalizedRm,
            );
        }

        // 5. Shared hub ceiling, counted separately per type.
        $denial = $this->quota->preview($this->importType(), (int) $branch->branchId);

        if (is_string($denial) && $denial !== '') {
            return LegacyMassUploadItemVerdict::blocked(
                LegacyMassUploadReason::CAPACITY_EXCEEDED,
                $denial,
                $branch->branchId,
                $normalizedRm,
            );
        }

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
        // Re-assert the binding immediately before creation.
        //
        // Not redundant: LegacyOdontogramImportService cannot perform this
        // check itself (it takes no sourceRm), so unlike every other gate it is
        // NOT re-evaluated inside the canonical call. Preflight alone would
        // leave a window in which the manifest row and the patient could have
        // been resolved from different states. Asserting here keeps the gate on
        // the write path, which is the only place a gate counts.
        $normalizedRm = $this->normalizer->normalize($row->medicalRecordNumber);
        $bindingResult = $this->binding->bind($normalizedRm, $patient);

        if ($bindingResult->failed()) {
            throw ValidationException::withMessages([
                'document' => [LegacyMassUploadReason::message(LegacyMassUploadReason::SOURCE_RM_MISMATCH)],
            ]);
        }

        $import = $this->imports->createFromUpload(
            patient: $patient,
            selectedOdontogramDate: (string) $row->selectedDate,
            document: $document,
            actor: $actor,
            // Backlog pathway; no fabricated visit (§31).
            attestation: null,
        );

        return (int) $import->getKey();
    }

    public function assignCreatedImport(LegacyMassUploadItem $item, int $importId): void
    {
        $item->odontogram_legacy_import_id = $importId;
    }

    public function quotaRemainingToday(int $branchId): ?int
    {
        return $this->quota->remainingToday($this->importType(), $branchId);
    }

    private function mapDateCode(?string $code): string
    {
        return match ($code) {
            LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM => LegacyMassUploadReason::NATIVE_ODONTOGRAM_BOUNDARY,
            LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_IN_FUTURE,
            LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH,
            LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_INVALID => LegacyMassUploadReason::DATE_INVALID,
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
