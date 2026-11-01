<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitActorGuard;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Odontogram\Support\FdiToothNumbers;
use App\Modules\PerformedTreatment\Interfaces\RmePerformedTreatmentRepositoryInterface;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmeOperationalVisitCategory;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentAuditEvent;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentEvidence;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentState;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219; owner CB-Q1, DPT-Q1) —
 * the single authority over the doctor's structured record of what was ACTUALLY
 * performed at a visit ("Tindakan Dilakukan").
 *
 * WHO. Only the TREATING doctor: an active account with the Doctor role, linked
 * (DoctorIdentityResolver) to an ACTIVE doctor record that IS the visit's
 * `doctor_id`, holding the ClinicVisitPolicy ability (examination authority +
 * branch + room + patient scope). A cashier, the front office, a nurse or a Super
 * Admin never authors it: the identity rule is a business rule, asserted here,
 * where Gate::before cannot reach.
 *
 * WHEN. Only inside the consented live encounter (RmeVisitConsentService::
 * assertPerformedTreatmentAuthoringAllowed), re-checked under the visit row lock:
 * the examination-completion UPDATE takes the same row lock, so a write either
 * commits while the visit is in_progress or is refused. After "Selesai
 * Pemeriksaan" the record is read-only (a post-completion amendment workflow is an
 * owner decision, DPT-Q4).
 *
 * WHAT. Append-only rows (a mistake is VOIDED with a reason, never edited) and one
 * explicit declaration per visit. "Tidak Ada Tindakan" makes the visit's
 * OPERATIONAL category KONTROL (DPT-Q1) — it never rewrites visit_type, never
 * creates a parent visit, and never makes anything free (rule 217 decides Rp0).
 * An empty list is NOT_RECORDED, never KONTROL.
 *
 * NEVER. This service changes no visit status (it does not depend on
 * ClinicVisitService), creates no invoice, price, payment, lab work or SATUSEHAT
 * resource, and reads no free text.
 */
class RmePerformedTreatmentService
{
    public const ABILITY = 'recordPerformedTreatments';

    public function __construct(
        private readonly RmePerformedTreatmentRepositoryInterface $rows,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly RmePerformedTreatmentFeatureGuard $feature,
        private readonly ClinicVisitActorGuard $actors,
        private readonly DoctorIdentityResolver $doctors,
        private readonly RmeVisitConsentService $consent,
        private readonly AuditLogService $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->feature->enabled();
    }

    /*
    |--------------------------------------------------------------------------
    | Writes
    |--------------------------------------------------------------------------
    */

    public function declare(ClinicVisit $visit, string $type, User $actor): RmeTreatmentDeclaration
    {
        $this->authorize($visit, $actor);

        if (! in_array($type, RmeTreatmentDeclaration::TYPES, true)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::DECLARATION_INVALID);
        }

        return DB::transaction(function () use ($visit, $type, $actor): RmeTreatmentDeclaration {
            [$locked, , $doctor] = $this->lockAuthorizedEncounter($visit, $actor);

            $current = $this->rows->declarationFor((int) $locked->id);

            if ($type === RmeTreatmentDeclaration::TYPE_NO_TREATMENT && $this->rows->activeCount((int) $locked->id) > 0) {
                throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::HAS_ACTIVE_TREATMENTS);
            }

            // Re-declaring the same answer is a no-op: no version bump, no audit row.
            if ($current !== null && $current->declaration_type === $type) {
                return $current;
            }

            return $this->putDeclaration($locked, $type, $current?->declaration_type, $actor, $doctor);
        });
    }

    /**
     * @param  array<string, mixed>  $data  treatment_id, quantity, tooth_numbers, submission_token
     */
    public function record(ClinicVisit $visit, array $data, User $actor): RmePerformedTreatment
    {
        $this->authorize($visit, $actor);

        $token = $this->submissionToken($data['submission_token'] ?? null);
        $quantity = $this->quantity($data['quantity'] ?? null);
        $teeth = $this->toothNumbers($data['tooth_numbers'] ?? null);
        $treatment = $this->selectableTreatment($data['treatment_id'] ?? null);

        return DB::transaction(function () use ($visit, $actor, $token, $quantity, $teeth, $treatment): RmePerformedTreatment {
            [$locked, , $doctor] = $this->lockAuthorizedEncounter($visit, $actor);
            $visitId = (int) $locked->id;

            // A resubmitted form (double click, back + resend) is a REPLAY of the
            // row it already wrote — never a second row (rule 205 pattern). The
            // token is bound to its payload: the same token carrying a different
            // treatment, quantity or teeth is a stale form, and returning the old
            // row would report success for a treatment that was never recorded.
            $replay = $this->rows->findBySubmissionToken($visitId, $token);
            if ($replay !== null) {
                if (! $this->isSamePayload($replay, (int) $treatment->id, $quantity, $teeth)) {
                    throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::SUBMISSION_REUSED);
                }

                return $replay;
            }

            $declaration = $this->rows->declarationFor($visitId);

            if ($declaration?->isNoTreatment()) {
                throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::DECLARED_NO_TREATMENT);
            }

            if ($this->rows->findActiveDuplicate($visitId, (int) $treatment->id, $teeth) !== null) {
                throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::DUPLICATE_ACTIVE);
            }

            // Recording a treatment IS the "Ada Tindakan" answer.
            if ($declaration === null) {
                $this->putDeclaration($locked, RmeTreatmentDeclaration::TYPE_HAS_TREATMENT, null, $actor, $doctor);
            }

            $row = $this->rows->createRow([
                'clinic_visit_id' => $visitId,
                'treatment_id' => (int) $treatment->id,
                'treatment_code_snapshot' => (string) $treatment->code,
                'treatment_name_snapshot' => (string) $treatment->name,
                'treatment_category_code_snapshot' => $treatment->category?->code !== null ? (string) $treatment->category->code : null,
                'requires_lab_snapshot' => (bool) $treatment->requires_lab,
                'quantity' => $quantity,
                'tooth_numbers' => $teeth,
                'status' => RmePerformedTreatment::STATUS_PERFORMED,
                'submission_token' => $token,
                'recorded_by' => (int) $actor->id,
                'recorded_by_doctor_id' => (int) $doctor->id,
                'recorded_at' => now(),
            ]);

            $this->audit->log(RmePerformedTreatmentAuditEvent::ENTITY_TYPE, $visitId, RmePerformedTreatmentAuditEvent::RECORDED, null, [
                'performed_treatment_id' => (int) $row->id,
                'treatment_id' => (int) $treatment->id,
                'treatment_code' => (string) $treatment->code,
                'treatment_category_code' => $row->treatment_category_code_snapshot,
                'requires_lab' => (bool) $treatment->requires_lab,
                'quantity' => $quantity,
                'tooth_count' => $teeth === null ? 0 : count($teeth),
                'doctor_id' => (int) $doctor->id,
            ], $actor);

            return $row;
        });
    }

    public function void(ClinicVisit $visit, RmePerformedTreatment $row, string $reason, User $actor): RmePerformedTreatment
    {
        $this->authorize($visit, $actor);

        $reason = trim($reason);
        if ($reason === '') {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::VOID_REASON_REQUIRED);
        }
        $reason = mb_substr($reason, 0, RmePerformedTreatment::VOID_REASON_MAX);

        return DB::transaction(function () use ($visit, $row, $reason, $actor): RmePerformedTreatment {
            [$locked, , $doctor] = $this->lockAuthorizedEncounter($visit, $actor);

            $current = $this->rows->findForVisit((int) $locked->id, (int) $row->id, true);

            if ($current === null || ! $current->isPerformed()) {
                throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ROW_NOT_ACTIVE);
            }

            $this->rows->markVoided($current, (int) $actor->id, $reason);

            $this->audit->log(RmePerformedTreatmentAuditEvent::ENTITY_TYPE, (int) $locked->id, RmePerformedTreatmentAuditEvent::VOIDED, [
                'status' => RmePerformedTreatment::STATUS_PERFORMED,
            ], [
                'performed_treatment_id' => (int) $current->id,
                'treatment_id' => (int) $current->treatment_id,
                'treatment_code' => (string) $current->treatment_code_snapshot,
                'status' => RmePerformedTreatment::STATUS_VOIDED,
                // The reason itself stays on the clinical row; it may name a person.
                'void_reason_length' => mb_strlen($reason),
                'doctor_id' => (int) $doctor->id,
            ], $actor);

            return $current;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    */

    /** The visit's declaration state (a pure read of committed rows). */
    public function stateFor(ClinicVisit $visit): string
    {
        return RmePerformedTreatmentState::derive(
            $this->rows->declarationFor((int) $visit->id)?->declaration_type,
            $this->rows->activeCount((int) $visit->id),
        );
    }

    /** The operational category (DPT-Q1) — null while the capability is OFF or nothing was declared. */
    public function operationalCategoryFor(ClinicVisit $visit): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        return RmePerformedTreatmentState::operationalCategory($this->stateFor($visit));
    }

    /** @return Collection<int, RmePerformedTreatment> */
    public function historyFor(ClinicVisit $visit): Collection
    {
        return $this->rows->historyFor((int) $visit->id);
    }

    /** The read-only clinical input of rule 218 and the cashier projection; null while OFF. */
    public function evidenceFor(ClinicVisit $visit): ?RmePerformedTreatmentEvidence
    {
        if (! $this->enabled()) {
            return null;
        }

        $declaration = $this->rows->declarationFor((int) $visit->id);
        $active = $this->rows->activeFor((int) $visit->id);
        $state = RmePerformedTreatmentState::derive($declaration?->declaration_type, $active->count());

        return new RmePerformedTreatmentEvidence(
            $state,
            RmePerformedTreatmentState::operationalCategory($state),
            $declaration?->version !== null ? (int) $declaration->version : null,
            $active,
        );
    }

    /**
     * Presentation predicate: may this viewer author the record right now? Never
     * the authorization answer — every write re-asserts it.
     */
    public function canAuthor(ClinicVisit $visit, User $viewer): bool
    {
        try {
            $this->authorize($visit, $viewer);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * The doctor workspace section ("Tindakan Dilakukan"); null while OFF so the
     * page renders exactly as before.
     *
     * @return array<string, mixed>|null
     */
    public function workspaceFor(ClinicVisit $visit, User $viewer): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $declaration = $this->rows->declarationFor((int) $visit->id);
        $history = $this->rows->historyFor((int) $visit->id);
        $active = $history->filter(fn (RmePerformedTreatment $row) => $row->isPerformed())->values();
        $state = RmePerformedTreatmentState::derive($declaration?->declaration_type, $active->count());
        $category = RmePerformedTreatmentState::operationalCategory($state);
        $canAuthor = $this->canAuthor($visit, $viewer);

        return [
            'state' => $state,
            'state_label' => RmePerformedTreatmentState::label($state),
            'declaration_type' => $declaration?->declaration_type,
            'declaration' => $declaration,
            'operational_category' => $category,
            'operational_category_label' => RmeOperationalVisitCategory::label($category),
            'active_rows' => $active,
            'voided_rows' => $history->reject(fn (RmePerformedTreatment $row) => $row->isPerformed())->values(),
            'can_author' => $canAuthor,
            'treatments' => $canAuthor ? $this->selectableTreatments() : collect(),
            'fdi_numbers' => FdiToothNumbers::all(),
            'max_quantity' => RmePerformedTreatment::MAX_QUANTITY,
            'submission_token' => (string) Str::uuid(),
        ];
    }

    /**
     * What the cashier may see of the doctor's record: treatment code, name,
     * quantity and a tooth COUNT (never a tooth number or a note), and whether an
     * invoice line carries each treatment's identity. Display only — it changes no
     * price and refuses nothing. Null while OFF.
     *
     * @return array<string, mixed>|null
     */
    public function cashierProjectionFor(ClinicVisit $visit, ?RmeInvoice $invoice): ?array
    {
        $evidence = $this->evidenceFor($visit);

        if ($evidence === null) {
            return null;
        }

        $validControl = RmeZeroValueEligibility::isControlVisit($visit) && RmeZeroValueEligibility::followsExaminedVisit($visit);

        $billed = [];
        $billedNotPerformed = [];

        if ($invoice !== null) {
            $invoice->loadMissing(['items.treatment' => fn ($q) => $q->withTrashed()->with(['category' => fn ($c) => $c->withTrashed()])]);
            $billed = $invoice->items->pluck('treatment_id')->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->values()->all();

            if ($evidence->state === RmePerformedTreatmentState::HAS_PERFORMED_TREATMENTS) {
                $performed = $evidence->performedTreatmentIds();
                foreach ($invoice->items as $item) {
                    $id = $item->treatment_id !== null ? (int) $item->treatment_id : null;
                    if ($id === null || in_array($id, $performed, true)) {
                        continue;
                    }
                    // An approved free service (Konsultasi / Kontrol) is not a "tindakan".
                    if (RmeZeroValueEligibility::lineRefusalCode($item->treatment, 0, 0, $validControl) === null) {
                        continue;
                    }
                    $billedNotPerformed[$id] = $id;
                }
                ksort($billedNotPerformed);
            }
        }

        $unbilled = $invoice !== null ? $evidence->unbilledTreatmentIds($billed, $validControl) : [];

        return [
            'state' => $evidence->state,
            'state_label' => RmePerformedTreatmentState::label($evidence->state),
            'operational_category' => $evidence->operationalCategory,
            'operational_category_label' => RmeOperationalVisitCategory::label($evidence->operationalCategory),
            'rows' => $evidence->activeRows->map(fn (RmePerformedTreatment $row) => [
                'treatment_id' => (int) $row->treatment_id,
                'code' => (string) $row->treatment_code_snapshot,
                'name' => (string) $row->treatment_name_snapshot,
                'quantity' => (int) $row->quantity,
                'tooth_count' => count($row->tooth_numbers ?? []),
                'billed' => $invoice !== null ? in_array((int) $row->treatment_id, $billed, true) : null,
                'unbilled' => in_array((int) $row->treatment_id, $unbilled, true),
            ])->values()->all(),
            'unbilled_count' => count($unbilled),
            'billed_not_performed' => array_values($billedNotPerformed),
        ];
    }

    /** @return Collection<int, Treatment> */
    public function selectableTreatments(): Collection
    {
        return $this->rows->selectableTreatments($this->excludedCategoryCodes(), $this->excludedTreatmentCodes());
    }

    /*
    |--------------------------------------------------------------------------
    | Authority
    |--------------------------------------------------------------------------
    */

    /** @return array{0: User, 1: Doctor} */
    private function authorize(ClinicVisit $visit, User $actor): array
    {
        if (! $this->enabled()) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::NOT_ENABLED);
        }

        $account = $this->actors->resolveForVisit($actor, self::ABILITY, $visit);

        if ($account === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED);
        }

        $doctor = $this->treatingDoctor($account, $visit);

        if ($doctor === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR);
        }

        $this->consent->assertPerformedTreatmentAuthoringAllowed($visit, $account);

        return [$account, $doctor];
    }

    /**
     * The linearization point of every write: the visit row is locked, then the
     * examination status, the consent and the actor are re-read AFTER the lock. A
     * status change committed before the lock refuses the write; one committed
     * after it waits for this transaction.
     *
     * @return array{0: ClinicVisit, 1: User, 2: Doctor}
     */
    private function lockAuthorizedEncounter(ClinicVisit $visit, User $actor): array
    {
        $locked = $this->visits->lockForUpdate((int) $visit->id);

        if ($locked === null || $locked->status !== ClinicVisit::STATUS_IN_PROGRESS) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE);
        }

        if (! $this->consent->hasValidConsent($locked)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::CONSENT_REQUIRED);
        }

        $account = $this->actors->resolveForVisit($actor, self::ABILITY, $locked);

        if ($account === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED);
        }

        // The full encounter authority again, now against the locked row: a second
        // encounter opened for the patient behind the lock makes the current one
        // ambiguous, which fails closed (rule 109).
        $this->consent->assertPerformedTreatmentAuthoringAllowed($locked, $account);

        $doctor = $this->treatingDoctor($account, $locked);

        if ($doctor === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::NOT_TREATING_DOCTOR);
        }

        return [$locked, $account, $doctor];
    }

    /** @param list<string>|null $teeth */
    private function isSamePayload(RmePerformedTreatment $row, int $treatmentId, int $quantity, ?array $teeth): bool
    {
        $stored = $row->tooth_numbers;
        $stored = is_array($stored) && $stored !== [] ? array_values(array_map('strval', $stored)) : null;
        if ($stored !== null) {
            sort($stored, SORT_STRING);
        }

        return (int) $row->treatment_id === $treatmentId
            && (int) $row->quantity === $quantity
            && $stored === $teeth;
    }

    private function treatingDoctor(User $account, ClinicVisit $visit): ?Doctor
    {
        if (! $account->hasRole('Doctor') || $visit->doctor_id === null) {
            return null;
        }

        $doctor = $this->doctors->resolveForUser($account);

        if ($doctor === null || ! (bool) $doctor->is_active || (int) $doctor->id !== (int) $visit->doctor_id) {
            return null;
        }

        return $doctor;
    }

    private function putDeclaration(ClinicVisit $visit, string $type, ?string $previous, User $actor, Doctor $doctor): RmeTreatmentDeclaration
    {
        $category = $type === RmeTreatmentDeclaration::TYPE_NO_TREATMENT ? RmeOperationalVisitCategory::CONTROL : null;

        $declaration = $this->rows->putDeclaration((int) $visit->id, $type, $category, (int) $actor->id, (int) $doctor->id);

        $this->audit->log(RmePerformedTreatmentAuditEvent::ENTITY_TYPE, (int) $visit->id, RmePerformedTreatmentAuditEvent::DECLARED, [
            'declaration_type' => $previous,
        ], [
            'declaration_type' => $type,
            'operational_visit_category' => $category,
            'version' => (int) $declaration->version,
            'doctor_id' => (int) $doctor->id,
        ], $actor);

        return $declaration;
    }

    /*
    |--------------------------------------------------------------------------
    | Input normalization (fail closed, never coerce a meaning)
    |--------------------------------------------------------------------------
    */

    private function submissionToken(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::SUBMISSION_INVALID);
        }

        return strtolower($value);
    }

    private function quantity(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1 || $value > RmePerformedTreatment::MAX_QUANTITY) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::QUANTITY_INVALID);
        }

        return $value;
    }

    /** @return list<string>|null */
    private function toothNumbers(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::TOOTH_INVALID);
        }

        $teeth = [];
        foreach ($value as $tooth) {
            if (! FdiToothNumbers::isValid($tooth)) {
                throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::TOOTH_INVALID);
            }
            $teeth[$tooth] = $tooth;
        }

        $teeth = array_values($teeth);
        sort($teeth, SORT_STRING);

        return $teeth;
    }

    private function selectableTreatment(mixed $value): Treatment
    {
        $id = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);

        $treatment = $id === null ? null
            : $this->rows->findSelectableTreatment($id, $this->excludedCategoryCodes(), $this->excludedTreatmentCodes());

        if ($treatment === null) {
            throw RmePerformedTreatmentRefusal::exception(RmePerformedTreatmentRefusal::TREATMENT_NOT_SELECTABLE);
        }

        return $treatment;
    }

    /**
     * A "tindakan" is a procedure: the approved free SERVICES (Konsultasi and the
     * reserved Kontrol category, rule 217) are not recorded as performed
     * treatments, so "Tidak Ada Tindakan" stays truthful on a Konsultasi/Kontrol
     * visit. Reuses the rule 217 lists — never a second copy.
     *
     * @return list<string>
     */
    private function excludedCategoryCodes(): array
    {
        return array_values(array_map('strval', array_merge(
            (array) config('rme_zero_value_closure.consultation_category_codes', []),
            (array) config('rme_zero_value_closure.control_service_category_codes', []),
        )));
    }

    /** @return list<string> */
    private function excludedTreatmentCodes(): array
    {
        return array_values(array_map('strval', (array) config('rme_zero_value_closure.excluded_treatment_codes', [])));
    }
}
