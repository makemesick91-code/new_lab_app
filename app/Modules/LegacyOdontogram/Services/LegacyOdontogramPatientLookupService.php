<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramPatientIdentity;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramPatientLookup;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — turn what an operator typed into
 * a patient, or into an honest explanation of why it is not one.
 *
 * TWO IDENTIFIERS, ON PURPOSE.
 *
 *   `patient_id`  the surrogate key. Unambiguous, and the only thing a
 *                 disambiguation link or an existing bookmark can carry.
 *   `rm`          the canonical Nomor RM. This is the identifier operators
 *                 ACTUALLY hold — it is printed on the chart in front of them
 *                 and it is what every other patient-selection surface in
 *                 DaengtisiaMS uses.
 *
 * The page previously offered only the first, so an operator typed the Nomor RM
 * into a field expecting a database row id, `Request::integer()` turned it into
 * 0, and the screen went blank with no explanation. Accepting the Nomor RM is
 * not a new feature; it is the difference between the existing workflow working
 * and not working.
 *
 * FAILURE IS A STATE, NOT A NULL. Every path returns a
 * {@see LegacyOdontogramPatientLookup} carrying its own words, so the page can
 * never again render "nothing entered yet" at someone who entered something.
 *
 * FINDING A PATIENT IS NOT PERMISSION TO ARCHIVE AGAINST THEM. This service
 * answers "who is this?" only. The owning branch, the operator's scope, the
 * date bounds and the duplicate check are all decided elsewhere, and all of
 * them run again server-side when the document is actually submitted.
 */
class LegacyOdontogramPatientLookupService
{
    public function __construct(
        private readonly LegacyOdontogramPatientRepositoryInterface $patients,
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * @param  bool  $identifierSupplied  whether the operator submitted anything at
     *                                    all. Distinguishes "not started yet" from
     *                                    "entered something unusable", which the
     *                                    old code could not tell apart.
     */
    public function lookup(
        ?User $actor,
        ?int $patientId,
        ?string $medicalRecordNumber,
        bool $identifierSupplied,
    ): LegacyOdontogramPatientLookup {
        if ($patientId === null && $medicalRecordNumber === null) {
            // Something was typed, but nothing usable survived sanitisation —
            // a stray array, an overlong string, a negative number. Saying "not
            // found" is honest; saying nothing is what caused this sprint.
            return $identifierSupplied
                ? LegacyOdontogramPatientLookup::notFound()
                : LegacyOdontogramPatientLookup::idle();
        }

        try {
            return $patientId !== null
                ? $this->byId($actor, $patientId)
                : $this->byMedicalRecordNumber($actor, (string) $medicalRecordNumber);
        } catch (Throwable $exception) {
            /*
             * The operator gets a retry instruction and the page still renders.
             * A lookup failure must never become a white 500 on a migration
             * screen, and must never be mistaken for "this patient does not
             * exist" — that is how a real patient gets registered twice.
             *
             * The identifier is patient-identifying and stays out of the log —
             * which takes more care than it looks. `QueryException::getMessage()`
             * INTERPOLATES the query bindings into the message it returns, so
             * logging it raw would write the operator's searched Nomor RM into
             * `laravel.log` on exactly the database faults this branch exists to
             * handle. The message is cut at the bindings and then masked, so the
             * diagnostic survives and the patient does not appear.
             */
            Log::warning('Legacy odontogram patient lookup failed.', [
                'exception' => $exception::class,
                'reason' => $this->safeReason($exception),
                'lookup_by' => $patientId !== null ? 'patient_id' : 'medical_record_number',
            ]);

            return LegacyOdontogramPatientLookup::error();
        }
    }

    /**
     * A diagnostic that cannot carry the identifier.
     *
     * Laravel appends " (Connection: …, SQL: …)" to a QueryException's message
     * with the bindings substituted in; everything from that marker onwards is
     * dropped. What remains is the driver's own error text, which is then run
     * through the canonical masker as a second line of defence for exception
     * types this method has not seen.
     */
    private function safeReason(Throwable $exception): string
    {
        $reason = Str::before($exception->getMessage(), ' (Connection:');

        return Str::limit($this->masker->mask($reason), 300);
    }

    private function byId(?User $actor, int $patientId): LegacyOdontogramPatientLookup
    {
        $patient = $this->patients->findSelectableById($actor, $patientId);

        return $patient instanceof Patient
            ? LegacyOdontogramPatientLookup::found(LegacyOdontogramPatientIdentity::fromPatient($patient))
            : LegacyOdontogramPatientLookup::notFound();
    }

    private function byMedicalRecordNumber(?User $actor, string $medicalRecordNumber): LegacyOdontogramPatientLookup
    {
        $limit = CrossBranchPatientLookupService::DISPLAY_LIMIT;

        // One row over the display limit, purely as a sentinel: it tells the
        // operator to type more digits instead of silently truncating matches.
        $matches = $this->patients->searchByMedicalRecordNumber($actor, $medicalRecordNumber, $limit + 1);

        if ($matches->isEmpty()) {
            return mb_strlen(trim($medicalRecordNumber)) < CrossBranchPatientLookupService::MIN_SUFFIX_LENGTH
                ? LegacyOdontogramPatientLookup::tooShort(CrossBranchPatientLookupService::MIN_SUFFIX_LENGTH)
                : LegacyOdontogramPatientLookup::notFound();
        }

        if ($matches->count() > $limit) {
            return LegacyOdontogramPatientLookup::tooMany();
        }

        if ($matches->count() === 1) {
            return LegacyOdontogramPatientLookup::found(
                LegacyOdontogramPatientIdentity::fromPatient($matches->first()),
            );
        }

        // Several real patients match. A human chooses — never this service.
        return LegacyOdontogramPatientLookup::ambiguous(
            $matches->map(LegacyOdontogramPatientIdentity::fromPatient(...))->values()->all(),
        );
    }
}
