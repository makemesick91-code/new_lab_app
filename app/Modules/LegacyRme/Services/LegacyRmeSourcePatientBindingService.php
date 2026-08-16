<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Support\LegacyRmePatientResolution;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmBinding;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmFailure;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — THE single place that answers:
 *
 *     "Does the Nomor RM printed on this document name the patient
 *      it is about to be filed under?"
 *
 * WHY THIS EXISTS. Wave-2 produced a real wrong-patient binding in production.
 * The operator selected one patient and uploaded a document belonging to
 * another, and nothing on the write path could tell — because the RM printed on
 * the paper was never captured, so there was no independent assertion to compare
 * the selection against. It was caught afterwards, from a frozen source hash and
 * manual evidence. LEGACY-RME-MASTERDATA-1 recorded the gap in writing; this
 * class closes it, by making the document state who it is about and then
 * refusing to proceed unless the server agrees.
 *
 * THE CHAIN, AND EVERY LINK FAILS CLOSED:
 *
 *     human-confirmed source RM
 *     → canonical normalization        (LegacyRmeSourceRmNormalizer)
 *     → EXACT identity resolution      (LegacyRmePatientResolutionAuditService)
 *     → exactly one patient
 *     → compare with the SELECTED patient
 *     → the branch segment the document asserts must agree with the patient's
 *
 * The archive's OWNING branch is not decided here — FIX-ROLL2-1's
 * LegacyRmeBranchResolver owns that, and this gate deliberately does not
 * duplicate it.
 *
 * NOTHING FUZZY, EVER. Identity comes from
 * {@see LegacyRmePatientResolutionAuditService::resolveIdentity()}, which
 * matches exactly or on a WHOLE manual segment and nothing else. This class
 * never sees, requests or computes a near-miss: `27541` does not become `22541`
 * here, at any distance, for any reason. MASTERDATA-1's near-miss signal is a
 * lead for a human at a terminal and is deliberately unreachable from the write
 * path.
 *
 * IT IS NOT THE ONLY GATE, AND IT REPLACES NONE OF THEM. The date rules, the
 * RM-derived branch, wave admission, capacity, quota, the policy and separation
 * of duties all still apply exactly as before. This runs FIRST — a document
 * about the wrong patient should never reach a decision about anything else.
 *
 * IT WRITES NOTHING. No insert, no update, no audit row, no file. It reads and
 * it answers. Callers decide what a refusal means for them, which is what lets
 * the same answer serve an upload, a publish revalidation and a read-only
 * diagnostic without any of them re-implementing the rule.
 */
class LegacyRmeSourcePatientBindingService
{
    public function __construct(
        private readonly LegacyRmeSourceRmNormalizer $normalizer,
        private readonly LegacyRmePatientResolutionAuditService $resolution,
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
    ) {}

    /**
     * Bind a source RM to the patient an operator selected.
     *
     * The ONLY entry point for the rule. HTTP, the queue, the CLI and the
     * diagnostic all arrive here, so none of them can be a weaker door than
     * another.
     *
     * NO ACTOR, DELIBERATELY. This asks whether a DOCUMENT is about a PATIENT —
     * a question whose answer cannot depend on who is asking. Authorization is
     * a separate concern with its own owners (the route permission, the policy,
     * LegacyRmeWorkspaceScope and LegacyRmeBranchResolver's scope arm), and
     * mixing it in here would let a scope difference surface as an identity
     * failure, sending an operator to re-read a document when the real problem
     * was their access.
     */
    public function bind(?string $sourceRm, Patient $selectedPatient): LegacyRmeSourceRmBinding
    {
        $raw = $sourceRm !== null ? trim($sourceRm) : null;

        if ($raw === null || $raw === '') {
            return LegacyRmeSourceRmBinding::failure(LegacyRmeSourceRmFailure::SOURCE_RM_REQUIRED);
        }

        $normalized = $this->normalizer->normalize($raw);

        if ($normalized === null || ! $this->isPlausibleMedicalRecordNumber($normalized)) {
            return LegacyRmeSourceRmBinding::failure(
                LegacyRmeSourceRmFailure::SOURCE_RM_INVALID,
                $raw,
                $normalized,
            );
        }

        // EXACT identity, from the canonical resolver. `resolveIdentity()` is
        // the near-miss-free sibling of the MASTERDATA-1 diagnostic and shares
        // its matching implementation, so the write path and the audit can never
        // disagree about who a number names.
        $report = $this->resolution->resolveIdentity($normalized);

        $code = (string) $report['resolution'];
        $matches = (array) $report['matches'];

        // Ambiguity is REFUSED, never settled. A duplicated Nomor RM is a
        // master-data defect, and picking a row would file a patient's history
        // against someone else — the precise failure this sprint exists to make
        // impossible.
        if (! (bool) $report['bindable'] || count($matches) !== 1) {
            return LegacyRmeSourceRmBinding::failure(
                $this->refusalFor($code),
                $raw,
                $normalized,
            );
        }

        $resolvedPatientId = (int) $matches[0]['patient_id'];
        $selectedPatientId = (int) $selectedPatient->getKey();

        // THE COMPARISON THIS WHOLE SPRINT IS ABOUT.
        //
        // The refusal deliberately does NOT say which patient the number
        // resolved to. Naming them would answer "who owns this RM?" for anyone
        // who can reach the upload form, turning a safety gate into a
        // patient-enumeration oracle. The operator is told to re-read the
        // document, which is the action they actually need to take.
        if ($resolvedPatientId !== $selectedPatientId) {
            return LegacyRmeSourceRmBinding::failure(
                LegacyRmeSourceRmFailure::SOURCE_RM_PATIENT_MISMATCH,
                $raw,
                $normalized,
            );
        }

        // THE BRANCH THE DOCUMENT ITSELF ASSERTS.
        //
        // WHAT THIS IS NOT. It is NOT a re-derivation of the archive's owning
        // branch. FIX-ROLL2-1 owns that rule — LegacyRmeBranchResolver decides
        // it from the patient's Nomor RM, LegacyRmeImportService calls it a few
        // lines after this gate, and LegacyRmePublishService re-derives it at
        // publish time. Running it here as well would duplicate a rule that
        // already has an owner, add a redundant query to the write path, and —
        // because this gate runs FIRST — swallow FIX-ROLL2-1's own specific
        // refusals ("this branch is inactive", "not RME-enabled", "out of your
        // scope") behind a generic identity error. An operator would be told to
        // re-read the document when the real fix was the patient's master data.
        //
        // WHAT IT IS. A comparison between the branch segment the operator
        // TYPED and the branch segment of the patient's own canonical Nomor RM.
        // Pure parsing, no database, no overlap with anything.
        //
        // DEFENCE IN DEPTH — unreachable while identity resolution stays exact.
        // A full canonical value only binds on an EXACT match, at which point
        // the two segments are the same string; a bare manual number carries no
        // branch segment at all and is skipped. It is kept for the same reason
        // LegacyRmeBranchResolver keeps its ambiguous-branch arm: if the
        // equality it rests on were ever relaxed, the alternative would be to
        // file a document under a branch its own printed number denies.
        $assertedBranchCode = $this->medicalRecordNumbers->branchCodeFrom($normalized);
        $patientBranchCode = $this->medicalRecordNumbers->branchCodeFrom($selectedPatient->medical_record_number);

        if ($assertedBranchCode !== null && $assertedBranchCode !== $patientBranchCode) {
            return LegacyRmeSourceRmBinding::failure(
                LegacyRmeSourceRmFailure::SOURCE_RM_BRANCH_MISMATCH,
                $raw,
                $normalized,
                null,
                $assertedBranchCode,
            );
        }

        return LegacyRmeSourceRmBinding::success(
            $selectedPatientId,
            $raw,
            $normalized,
            $code,
            $assertedBranchCode ?? $patientBranchCode,
        );
    }

    /**
     * Re-verify the binding a STAGED import already carries.
     *
     * WHY REVALIDATION IS NOT PARANOIA. Acceptance proved the binding at upload
     * time; publishing freezes it into an immutable clinical record. Between the
     * two, a patient's Nomor RM can be corrected, a patient can be soft-deleted,
     * a duplicate can appear. The stored source RM is IMMUTABLE, so what is
     * re-asked is never "did the operator change their answer" — it is "does the
     * master data still agree with the answer they gave". This mirrors the
     * date and branch revalidation LegacyRmePublishService has always run.
     *
     * A PRE-ENFORCEMENT ROW FAILS CLOSED. An import staged before this sprint
     * has no captured source RM, so its patient binding cannot be verified at
     * all. It is refused with its own code rather than silently allowed: the row
     * is cancelled and re-imported, and cancel is deliberately the one lifecycle
     * action this domain never gates.
     */
    public function verifyStaged(LegacyRmeImport $import): LegacyRmeSourceRmBinding
    {
        $stored = $import->source_rm_normalized;

        if (! is_string($stored) || trim($stored) === '') {
            return LegacyRmeSourceRmBinding::failure(
                LegacyRmeSourceRmFailure::SOURCE_RM_CAPTURE_MISSING,
                $import->source_rm_raw,
            );
        }

        $patient = $import->patient;

        if ($patient === null) {
            // The patient the row points at is gone. Nothing can be verified,
            // and "cannot be found" is the truthful answer.
            return LegacyRmeSourceRmBinding::failure(
                LegacyRmeSourceRmFailure::SOURCE_RM_NOT_FOUND,
                $import->source_rm_raw,
                $stored,
            );
        }

        // The STORED normalized value is re-bound, never the raw transcription:
        // re-normalizing on every read would make a future change to the
        // normalizer silently re-interpret historical evidence.
        return $this->bind($stored, $patient);
    }

    /**
     * Map a MASTERDATA-1 resolution code onto the refusal an operator sees.
     *
     * The constants are referenced, never their literal values: if
     * LegacyRmePatientResolution ever renamed a code, a magic string here would
     * silently fall through to the `default` arm and report a MISS for what was
     * actually an AMBIGUITY — still fail-closed, but telling the operator to
     * re-read a document when the real fix is a duplicated Nomor RM.
     *
     * TOO_SHORT and EMPTY_INPUT collapse into SOURCE_RM_INVALID on purpose:
     * from the operator's side both mean "what you entered cannot be looked up
     * safely", and the remedy — read the document again — is identical.
     *
     * `default` is deliberately the strictest useful answer rather than an
     * exception: an unrecognised code must never become an acceptance.
     */
    private function refusalFor(string $resolutionCode): string
    {
        return match ($resolutionCode) {
            LegacyRmePatientResolution::CODE_EXACT_AMBIGUOUS,
            LegacyRmePatientResolution::CODE_SEGMENT_AMBIGUOUS => LegacyRmeSourceRmFailure::SOURCE_RM_AMBIGUOUS,

            LegacyRmePatientResolution::CODE_TOO_SHORT,
            LegacyRmePatientResolution::CODE_EMPTY_INPUT => LegacyRmeSourceRmFailure::SOURCE_RM_INVALID,

            default => LegacyRmeSourceRmFailure::SOURCE_RM_NOT_FOUND,
        };
    }

    /**
     * A cheap shape check before the master data is consulted at all.
     *
     * It accepts far more than it should have to — a canonical Nomor RM or a
     * bare manual number as printed on an old document — because deciding what
     * exists is the resolver's job, not this method's. It exists only to stop
     * obviously non-RM text (an over-long paste, a filename, a sentence) from
     * reaching a `LIKE` query.
     */
    private function isPlausibleMedicalRecordNumber(string $normalized): bool
    {
        $maxLength = (int) config('legacy_rme.source_rm.max_length', 64);

        if (mb_strlen($normalized) > max(1, $maxLength)) {
            return false;
        }

        $pattern = (string) config('legacy_rme.source_rm.allowed_pattern', '/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/');

        return preg_match($pattern, $normalized) === 1;
    }
}
