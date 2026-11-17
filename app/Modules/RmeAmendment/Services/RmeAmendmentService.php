<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ActiveEncounterResolver;
use App\Modules\Consent\Services\RmeVisitConsentService;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordHandwritingRepositoryInterface;
use App\Modules\MedicalRecord\Interfaces\MedicalRecordRepositoryInterface;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwritingPage;
use App\Modules\MedicalRecord\Services\DiagnosisRolloutService;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\MedicalRecord\Services\PatientRmWorkspaceResolver;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Odontogram\Requests\UpdateOdontogramPlaceholderRequest;
use App\Modules\Odontogram\Support\FdiToothNumbers;
use App\Modules\RmeAmendment\Interfaces\RmeAmendmentRepositoryInterface;
use App\Modules\RmeAmendment\Interfaces\RmeRecordVersionRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use App\Modules\RmeAmendment\Notifications\RmeAmendmentNotification;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Satusehat\Services\SatusehatCandidateService;
use App\Support\AccessControl\ActiveAccountResolver;
use App\Support\Storage\ClinicalEvidenceStorage;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — the supervised
 * amendment of a final sheet or of a finalized odontogram: request, cancel,
 * reject, approve-and-apply (§7, M3.3 PR5), and the reads behind the supervisor
 * queue, the detail page, the record page's "Amandemen" block and the version
 * history pages. Approvers are notified of a request and the requester of a
 * decision, after commit, ids only.
 *
 * Who (owner decisions of 2026-11-17, plan defaults 1, 2 and 6):
 * - Requester: an account holding `request_rme_amendments` that is the doctor of
 *   record (the sheet's `doctor_id`, or the chart visit's doctor, →
 *   `mst_doctors.user_id`) or the record's finalizer (`finalized_by`). Identity,
 *   not role: a Super Admin who is neither is refused.
 * - Checker: an account holding `approve_rme_amendments` (Supervisor RME; Super
 *   Admin through Gate::before) whose branch scope covers the amendment, that is
 *   not the requester, not the doctor of record and not the finalizer (creator ≠
 *   approver) — re-decided here inside the transaction on the locked rows, so it
 *   binds Super Admin (CG-06, CG-07); the PostgreSQL CHECK
 *   `decided_by <> requested_by` and the row trigger are the backstops.
 * - An account that may not `view` the record (or, for a decision, the
 *   amendment) is answered "not found" before anything else, so neither the
 *   record's state nor the amendment's existence leaks (CG-05).
 *
 * Every write locks the subject's visit (FOR NO KEY UPDATE), then the subject
 * row, then the amendment (rule 240 §1.4 extended), and re-decides on those rows.
 * The proposal never touches the record: the sheet and the chart stay locked
 * (rule 240 §1) until PR5 applies an approved amendment. Audit rows carry ids and
 * the reason's length only (CG-11); the reason and the proposal stay on the row.
 *
 * Rule 240 §8 (M3.3 PR6) — a sheet amendment may carry ONE handwriting
 * correction page. The request decodes and validates it (the handwriting canvas
 * decoder and bounds, plus a clean PNG structure), then writes it to the private
 * clinical disk under a fresh server key and inserts the row, as one unit (rule
 * 224: a failure in the body removes only this call's object; a committed object
 * is never deleted, a rejected or cancelled one is kept). Approving verifies the
 * object's SHA-256, locks the patient's book after the amendment and appends the
 * page to it as the NEXT page, sealed at once and stamped with the amendment and
 * the amended encounter; the next version's snapshot lists it.
 */
final class RmeAmendmentService
{
    public const PERMISSION_REQUEST = 'request_rme_amendments';

    /**
     * Rule 240 §9.5 (memo M3.3 PR7) — what a viewer who may not open the amended
     * visit sees of a correction page: that it is one, and nothing of that
     * visit's number or date, the approver or the amendment's number.
     */
    public const NEUTRAL_CORRECTION_PAGE_LABEL = 'Halaman koreksi';

    public const PERMISSION_APPROVE = 'approve_rme_amendments';

    public const AUDIT_ENTITY = 'trx_rme_amendments';

    public const AUDIT_REQUESTED = 'RME_AMENDMENT_REQUESTED';

    public const AUDIT_CANCELLED = 'RME_AMENDMENT_CANCELLED';

    public const AUDIT_REJECTED = 'RME_AMENDMENT_REJECTED';

    public const AUDIT_APPLIED = 'RME_AMENDMENT_APPLIED';

    /** The sheet's own text an amendment may change (rule 240 §1.1). */
    public const SHEET_FIELDS = [
        'subjective' => 'Subjektif',
        'objective' => 'Objektif',
        'assessment' => 'Asesmen',
        'plan' => 'Rencana',
        'notes' => 'Catatan',
    ];

    public const SHEET_FIELD_MAX = 10000;

    public const DIAGNOSIS_ADD_MAX = 10;

    public const MESSAGE_NOT_FINAL = 'Hanya rekam medis atau odontogram yang sudah final yang dapat diamandemen.';

    public const MESSAGE_NO_REQUEST_PERMISSION = 'Akun Anda tidak berwenang mengajukan amandemen rekam medis.';

    public const MESSAGE_NOT_REQUESTER = 'Amandemen hanya dapat diajukan oleh dokter penanggung jawab rekam medis ini atau oleh yang memfinalisasinya.';

    public const MESSAGE_PENDING_EXISTS = 'Rekam medis ini sudah memiliki permintaan amandemen yang menunggu keputusan.';

    public const MESSAGE_REASON = 'Alasan amandemen wajib diisi, 10–1000 karakter.';

    public const MESSAGE_DECISION_REASON = 'Alasan penolakan wajib diisi, 10–1000 karakter.';

    public const MESSAGE_NO_CHANGE = 'Usulan amandemen tidak mengubah apa pun pada rekam medis ini.';

    public const MESSAGE_NOT_PENDING = 'Permintaan amandemen ini sudah tidak menunggu keputusan.';

    public const MESSAGE_CANCEL_NOT_REQUESTER = 'Hanya pengaju yang dapat membatalkan permintaan amandemen ini.';

    public const MESSAGE_NO_APPROVE_PERMISSION = 'Akun Anda tidak berwenang memutuskan amandemen rekam medis.';

    public const MESSAGE_SELF_DECISION = 'Pengaju tidak dapat memutuskan permintaan amandemennya sendiri.';

    public const MESSAGE_DOCTOR_OF_RECORD = 'Dokter penanggung jawab rekam medis ini tidak dapat memutuskan amandemennya.';

    public const MESSAGE_FINALIZER = 'Yang memfinalisasi rekam medis ini tidak dapat memutuskan amandemennya.';

    public const MESSAGE_PRIMARY_REQUIRED = 'Cabang ini mewajibkan minimal satu diagnosis utama terstruktur; amandemen tidak boleh meninggalkan rekam medis tanpa diagnosis utama.';

    public const MESSAGE_TOOTH_MAP = 'Peta gigi usulan tidak terbaca. Muat ulang formulir lalu ajukan kembali.';

    public const MESSAGE_UNKNOWN_PART = 'Usulan amandemen memuat bagian yang tidak dikenal.';

    public const MESSAGE_DIAGNOSIS_NOT_ON_SHEET = 'Diagnosis yang akan dihapus bukan diagnosis rekam medis ini.';

    public const MESSAGE_MASTER_INACTIVE = 'Diagnosis master tidak ditemukan atau tidak aktif.';

    public const MESSAGE_SECOND_PRIMARY = 'Rekam medis hanya boleh memiliki satu diagnosis utama — hapus diagnosis utama sebelumnya dalam usulan yang sama.';

    public const MESSAGE_TOOTH_MAP_PART = 'Peta gigi usulan memuat bagian yang tidak dikenal.';

    public const MESSAGE_TOOTH_NUMBER = 'Nomor gigi tidak valid. Gunakan nomor FDI.';

    public const MESSAGE_TOOTH_FIELD = 'Gigi memuat isian yang tidak dikenal.';

    public const MESSAGE_CONDITIONS = 'Kondisi gigi harus berupa daftar kondisi yang dikenal, tanpa kosong dan tanpa duplikat.';

    public const MESSAGE_DIAGNOSIS_ROW = 'Usulan diagnosis tidak valid.';

    public const MESSAGE_DIAGNOSIS_ROLE = 'Peran diagnosis tidak valid.';

    public const MESSAGE_CHAIN_BROKEN = 'Riwayat versi rekam medis ini tidak utuh (rantai hash tidak cocok). Amandemen tidak dapat diterapkan; laporkan kepada Supervisor RME.';

    public const MESSAGE_STALE_BASE = 'Rekam medis sudah memiliki versi yang lebih baru daripada versi dasar amandemen ini. Pengaju perlu mengajukan amandemen baru.';

    public const MESSAGE_PROPOSAL_CHANGED = 'Usulan amandemen tidak lagi sesuai dengan rekam medis saat ini. Pengaju perlu mengajukan amandemen baru.';

    public const MESSAGE_DECISION_NOTE = 'Catatan persetujuan maksimal 1000 karakter.';

    /** Rule 240 §8 — only a sheet amendment carries a handwriting correction page. */
    public const MESSAGE_CORRECTION_PAGE_SHEET_ONLY = 'Halaman koreksi tulisan tangan hanya dapat diajukan pada amandemen rekam medis.';

    public const MESSAGE_CORRECTS_PAGE = 'Halaman yang dikoreksi tidak ditemukan pada buku RM pasien ini.';

    public const MESSAGE_CORRECTS_PAGE_WITHOUT_PAGE = 'Halaman yang dikoreksi hanya dapat dipilih bersama halaman koreksi tulisan tangan.';

    public const MESSAGE_CORRECTION_PAGE_INTEGRITY = 'Gambar halaman koreksi tidak lolos pemeriksaan integritas (SHA-256), sehingga amandemen ini tidak dapat diterapkan. Pengaju perlu mengajukan amandemen baru.';

    /** Review L2 — a storage read fault is not tampering: try again later. */
    public const MESSAGE_CORRECTION_PAGE_UNREADABLE = 'Gambar koreksi sementara tidak dapat dibaca; coba lagi.';

    /** Review M2 — a correction names only a page the amended encounter wrote. */
    public const MESSAGE_CORRECTS_PAGE_ENCOUNTER = 'Halaman yang dikoreksi bukan halaman yang ditulis pada kunjungan rekam medis ini.';

    /** The largest page number a book row can hold (int4). */
    private const PAGE_NUMBER_MAX = 2147483647;

    /** The keys of a diagnosis row a proposal may add. */
    private const DIAGNOSIS_ROW_KEYS = ['clinical_diagnosis_id', 'diagnosis_role', 'notes'];

    /** Labels of the per-tooth fields a chart save stores (UpdateOdontogramPlaceholderRequest). */
    private const TOOTH_FIELD_LABELS = [
        'status' => 'Diagnosa',
        'conditions' => 'Kondisi',
        'note' => 'Catatan',
        'additional_condition' => 'Detail diagnosa',
        'additional_note' => 'Perawatan',
        'dokter' => 'Dokter',
    ];

    /*
     * Rule 240 §6.11 (owner decision 2026-11-17, "AMENDMENT — APPROVE: No PTM gate,
     * record it") — the amended visit's PTM as its persisted consent evidence
     * records it, shown on the detail page for INFORMATION only.
     */
    public const ORIGINAL_PTM_EXECUTED = 'executed';

    public const ORIGINAL_PTM_PATIENT_ONLY = 'patient_only';

    public const ORIGINAL_PTM_NONE = 'none';

    public const ORIGINAL_PTM_VOIDED = 'voided';

    private const ORIGINAL_PTM_LABELS = [
        self::ORIGINAL_PTM_EXECUTED => 'Lengkap — ditandatangani pasien dan dokter yang menangani.',
        self::ORIGINAL_PTM_PATIENT_ONLY => 'Hanya tanda tangan pasien — belum ditandatangani dokter.',
        self::ORIGINAL_PTM_NONE => 'Tidak ada PTM tercatat untuk kunjungan ini.',
        self::ORIGINAL_PTM_VOIDED => 'PTM tercatat telah dibatalkan (void); tidak ada PTM yang berlaku.',
    ];

    private const ORIGINAL_PTM_NOTE = 'Hanya informasi: amandemen mengoreksi rekam medis yang sudah ada, sehingga tidak memerlukan PTM dan status ini tidak menghalangi keputusan. Tindakan medis baru dicatat melalui pemeriksaan dan PTM.';

    public function __construct(
        private readonly RmeAmendmentRepositoryInterface $amendments,
        private readonly RmeRecordVersionRepositoryInterface $versions,
        private readonly RmeRecordVersionService $versionService,
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly MedicalRecordRepositoryInterface $medicalRecords,
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly RmeWorkingBranchScope $branchScope,
        private readonly ActiveEncounterResolver $encounters,
        private readonly AuditLogService $auditLog,
        private readonly DiagnosisRolloutService $diagnosisRollout,
        private readonly ActiveAccountResolver $accounts,
        private readonly MedicalRecordHandwritingRepositoryInterface $handwritings,
        private readonly HandwritingPayloadGuard $canvasGuard,
        private readonly PatientRmWorkspaceResolver $workspace,
        private readonly RmeVisitConsentService $consents,
    ) {}

    // ─── Writes ─────────────────────────────────────────────────────────────

    /**
     * File a pending amendment of a final sheet or a finalized chart, against its
     * latest version (a record finalized before rule 240 first gets its baseline
     * version 1). Nothing in the record changes.
     *
     * @param  array<string, mixed>  $proposed  sheet: `fields` (changed SOAP/notes text)
     *                                          and `diagnoses` (`add`, `remove`);
     *                                          chart: `summary_notes`, `additional_conditions`,
     *                                          `tooth_map_payload` (absent = unchanged)
     * @param  string|null  $correctionPage  rule 240 §8 — a sheet only: ONE handwriting
     *                                       correction page, the canvas as posted
     *                                       (`data:image/png;base64,…`); with it the
     *                                       proposal may change nothing else
     * @param  int|null  $correctsPageNumber  the page of the patient's book it corrects
     */
    public function request(MedicalRecord|Odontogram $subject, User $requester, string $reason, array $proposed, ?string $correctionPage = null, ?int $correctsPageNumber = null): RmeAmendment
    {
        // "Not found" first, so an account that may not see the record learns
        // nothing from the order of the answers either.
        $this->assertVisibleSubject($subject, $requester);
        $reason = $this->boundedReason($reason, 'reason', self::MESSAGE_REASON);
        $this->assertRequester($subject, $requester);

        // Rule 240 §8 / rule 224 — validated in full before anything is written.
        $page = $this->correctionPageInput($subject, $correctionPage, $correctsPageNumber);

        $writtenKey = null;
        $bodyDone = false;

        try {
            return DB::transaction(function () use ($subject, $requester, $reason, $proposed, $page, $correctsPageNumber, &$writtenKey, &$bodyDone): RmeAmendment {
                $locked = $this->lockSubject($subject);

                // Re-decided on the locked row, binding Super Admin.
                $this->assertRequester($locked, $requester);
                [$type, $id] = $this->subjectKey($locked);

                if ($this->amendments->pendingFor($type, $id) !== null) {
                    throw $this->refusal('amendment', self::MESSAGE_PENDING_EXISTS);
                }

                $content = $locked instanceof Odontogram
                    ? $this->chartProposal($locked, $proposed)
                    : $this->sheetProposal($locked, $proposed, allowEmpty: $page !== null);

                if ($page !== null && $correctsPageNumber !== null) {
                    $this->assertBookPage($this->bookOf($locked), $locked, $correctsPageNumber, 'corrects_page_number', self::MESSAGE_CORRECTS_PAGE, self::MESSAGE_CORRECTS_PAGE_ENCOUNTER);
                }

                $base = $this->versions->latestFor($type, $id)
                    ?? $this->versionService->ensureBaseline($locked, (int) $requester->getKey(), $this->liveEncounterId($locked));

                // Rule 240 §8 — the object first (a fresh server key no row names
                // yet), then the row; a failure from here to the end of the body
                // removes this object and only this one.
                $correction = [];
                if ($page !== null) {
                    $writtenKey = $this->correctionObjectKey($locked);
                    ClinicalEvidenceStorage::disk()->put($writtenKey, $page);
                    $correction = [
                        'correction_page_path' => $writtenKey,
                        'correction_page_sha256' => hash('sha256', $page),
                        'corrects_page_number' => $correctsPageNumber,
                    ];
                }

                try {
                    // A savepoint: on PostgreSQL a unique violation aborts the
                    // transaction, so it is rolled back to here and refused cleanly.
                    $amendment = DB::transaction(fn (): RmeAmendment => $this->amendments->create([
                        'subject_type' => $type,
                        'subject_id' => $id,
                        'branch_id' => (int) $locked->branch_id,
                        'clinic_visit_id' => (int) $locked->clinic_visit_id,
                        'base_version_id' => (int) $base->getKey(),
                        'base_version' => (int) $base->version,
                        'reason' => $reason,
                        'proposed_content' => $content,
                        'requested_by' => (int) $requester->getKey(),
                    ] + $correction));
                } catch (QueryException $e) {
                    if (! $this->isPendingUniqueViolation($e)) {
                        throw $e;
                    }

                    throw $this->refusal('amendment', self::MESSAGE_PENDING_EXISTS);
                }

                $this->auditLog->log(self::AUDIT_ENTITY, (int) $amendment->getKey(), self::AUDIT_REQUESTED, null, [
                    'subject_type' => $type,
                    'subject_id' => $id,
                    'branch_id' => (int) $amendment->branch_id,
                    'base_version' => (int) $amendment->base_version,
                    'base_version_id' => (int) $amendment->base_version_id,
                    'status' => RmeAmendment::STATUS_PENDING,
                    'reason_length' => mb_strlen($reason),
                ] + ($page !== null ? [
                    // Ids and the digest only — never the key or the image (CG-11).
                    'correction_page_sha256' => $correction['correction_page_sha256'],
                    'corrects_page_number' => $correctsPageNumber,
                ] : []), $requester);

                $this->notifyAfterCommit(
                    fn (): iterable => $this->approversFor($amendment, $locked),
                    'Permintaan amandemen RME',
                    'Permintaan amandemen #'.$amendment->getKey().' menunggu keputusan Anda.',
                    $amendment,
                );

                $bodyDone = true;

                return $amendment;
            });
        } catch (Throwable $e) {
            // Only while the body had not finished: after it (the COMMIT, an
            // after-commit callback) the row may be committed, so the object stays.
            if ($writtenKey !== null && ! $bodyDone) {
                $this->discardUncommitted($writtenKey);
            }

            throw $e;
        }
    }

    /** Withdraw a pending amendment — its requester only (identity, binds Super Admin). */
    public function cancel(RmeAmendment $amendment, User $actor): RmeAmendment
    {
        return DB::transaction(function () use ($amendment, $actor): RmeAmendment {
            [$locked] = $this->lockForDecision($amendment);

            if (! $locked->isPending()) {
                throw $this->refusal('amendment', self::MESSAGE_NOT_PENDING);
            }

            if ((int) $locked->requested_by !== (int) $actor->getKey()) {
                throw $this->refusal('amendment', self::MESSAGE_CANCEL_NOT_REQUESTER);
            }

            $locked = $this->amendments->transition($locked, [
                'status' => RmeAmendment::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $locked->getKey(), self::AUDIT_CANCELLED, ['status' => RmeAmendment::STATUS_PENDING], [
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'branch_id' => (int) $locked->branch_id,
                'status' => RmeAmendment::STATUS_CANCELLED,
            ], $actor);

            return $locked;
        });
    }

    /**
     * Refuse a pending amendment with a written reason. Account authority first,
     * then scope (out of scope is "not found"), then the state and separation of
     * duties (not the requester, the doctor of record or the finalizer) — all on
     * the locked rows, binding Super Admin.
     */
    public function reject(RmeAmendment $amendment, User $approver, string $decisionReason): RmeAmendment
    {
        $decisionReason = $this->boundedReason($decisionReason, 'decision_reason', self::MESSAGE_DECISION_REASON);

        return DB::transaction(function () use ($amendment, $approver, $decisionReason): RmeAmendment {
            if (! $approver->can(self::PERMISSION_APPROVE)) {
                throw $this->refusal('amendment', self::MESSAGE_NO_APPROVE_PERMISSION);
            }

            [$locked, $subject] = $this->lockForDecision($amendment);

            if (! $this->inApproverScope($approver, $locked, $subject)) {
                throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $locked->getKey()]);
            }

            $refusal = $this->decisionRefusal($locked, $subject, $approver);
            if ($refusal !== null) {
                throw $this->refusal('amendment', $refusal);
            }

            $locked = $this->amendments->transition($locked, [
                'status' => RmeAmendment::STATUS_REJECTED,
                'decided_by' => (int) $approver->getKey(),
                'decided_at' => now(),
                'decision_reason' => $decisionReason,
            ]);

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $locked->getKey(), self::AUDIT_REJECTED, ['status' => RmeAmendment::STATUS_PENDING], [
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'branch_id' => (int) $locked->branch_id,
                'status' => RmeAmendment::STATUS_REJECTED,
                'requested_by' => (int) $locked->requested_by,
                'decision_reason_length' => mb_strlen($decisionReason),
            ], $approver);

            $this->notifyAfterCommit(
                fn (): iterable => $this->requesterOf($locked),
                'Amandemen RME ditolak',
                'Permintaan amandemen #'.$locked->getKey().' ditolak.',
                $locked,
            );

            return $locked;
        });
    }

    /**
     * Approve AND apply, in one transaction, so the checker approves exactly
     * what is applied (rule 240 §7). Under visit → subject → amendment locks,
     * re-decided on the locked rows (binding Super Admin): the approve
     * permission, the scope (otherwise "not found"), the amendment pending, not
     * the requester, the doctor of record or the finalizer; the subject still
     * final; its latest version still the base of the request (otherwise the
     * requester files again); the stored proposal re-validated against the locked
     * record under today's rules and unchanged by it. Then the change is written
     * to the record — which stays final and locked: only this path writes — the
     * next version is chained to the base, and the amendment becomes `applied`.
     */
    public function approve(RmeAmendment $amendment, User $approver, ?string $decisionNote = null): RmeAmendment
    {
        $note = $this->text($decisionNote);
        if ($note !== null && mb_strlen(trim($note)) > RmeAmendment::REASON_MAX) {
            throw $this->refusal('decision_reason', self::MESSAGE_DECISION_NOTE);
        }
        $note = $note !== null ? trim($note) : null;

        return DB::transaction(function () use ($amendment, $approver, $note): RmeAmendment {
            if (! $approver->can(self::PERMISSION_APPROVE)) {
                throw $this->refusal('amendment', self::MESSAGE_NO_APPROVE_PERMISSION);
            }

            [$locked, $subject] = $this->lockForDecision($amendment);

            if (! $this->inApproverScope($approver, $locked, $subject)) {
                throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $locked->getKey()]);
            }

            $refusal = $this->decisionRefusal($locked, $subject, $approver);
            if ($refusal !== null) {
                throw $this->refusal('amendment', $refusal);
            }

            if (! $this->isFinal($subject)) {
                throw $this->refusal('amendment', self::MESSAGE_NOT_FINAL);
            }

            [$type, $id] = $this->subjectKey($subject);
            $latest = $this->versions->latestFor($type, $id);
            if ($latest === null || (int) $latest->getKey() !== (int) $locked->base_version_id) {
                throw $this->refusal('amendment', self::MESSAGE_STALE_BASE);
            }

            // Re-review — the next version is chained onto this history, so the
            // history must verify first (rule 240 §5.7): a broken chain is never
            // extended, and nothing is written.
            if (! $this->versionService->verifyChain($subject)) {
                throw $this->refusal('amendment', self::MESSAGE_CHAIN_BROKEN);
            }

            $content = $this->revalidated($locked, $subject);

            // Rule 240 §8 — the correction page: its object verified against the
            // SHA-256 and today's canvas rules, then the patient's book locked
            // (visit → sheet → amendment → book, the record lock a page save takes)
            // and the corrected page, if named, still in it.
            $book = null;
            if ($subject instanceof MedicalRecord && $locked->hasCorrectionPage()) {
                $this->verifiedCorrectionPage($locked);
                $book = $this->medicalRecords->lockForHandwriting((int) $this->bookOf($subject)->getKey())
                    ?? throw $this->refusal('amendment', self::MESSAGE_PROPOSAL_CHANGED);

                if ($locked->corrects_page_number !== null) {
                    $this->assertBookPage($book, $subject, (int) $locked->corrects_page_number, 'amendment', self::MESSAGE_PROPOSAL_CHANGED, self::MESSAGE_PROPOSAL_CHANGED);
                }
            }

            $sheetRowWritten = false;
            if ($subject instanceof Odontogram) {
                $subject = $this->odontograms->updatePlaceholder($subject, [
                    'summary_notes' => $content['summary_notes'],
                    'additional_conditions' => $content['additional_conditions'],
                    'tooth_map_payload' => $content['tooth_map_payload'],
                    'updated_by' => (int) $approver->getKey(),
                ]);
            } else {
                if (($content['fields'] ?? []) !== []) {
                    $subject = $this->medicalRecords->update($subject, $content['fields']);
                    $sheetRowWritten = true;
                }

                $diagnoses = $content['diagnoses'] ?? [];
                if ($diagnoses !== []) {
                    // Diagnosed by the requester (the clinician who proposed them);
                    // created by the approver whose decision wrote them.
                    $this->amendments->applySheetDiagnoses($subject, $diagnoses['add'] ?? [], $diagnoses['remove'] ?? [], (int) $locked->requested_by, (int) $approver->getKey());
                }
            }

            // Appended as the book's NEXT page, sealed at once, stored on the book's
            // visit and stamped with the amended encounter and this amendment —
            // never in place of a page. Before the version, so its snapshot lists it.
            $appended = $book !== null ? $this->handwritings->appendSealedAmendmentPage($book, [
                'handwriting_path' => $locked->correction_page_path,
                'handwriting_hash' => $locked->correction_page_sha256,
                'authored_clinic_visit_id' => (int) $subject->clinic_visit_id,
                'sealed_by_medical_record_id' => (int) $subject->getKey(),
                'rme_amendment_id' => (int) $locked->getKey(),
                // Drawn by the requester; written by the approver's decision.
                'created_by' => (int) $locked->requested_by,
                'updated_by' => (int) $approver->getKey(),
            ]) : null;

            $version = $this->versionService->recordAmendment($subject, $latest, (int) $approver->getKey(), $this->liveEncounterId($subject));

            // Rule 240 §7.3 — every apply refreshes the visit's SATUSEHAT candidate
            // after commit, so drift revokes an approval (`source_changed`); it
            // decides no re-submission. A saved sheet row is refreshed by
            // SatusehatMedicalRecordObserver (after commit, reading the diagnoses
            // written here too) — a second refresh would overwrite that
            // `source_changed` readiness — so this one runs for a diagnosis-only
            // apply (as MedicalRecordDiagnosisService does), for a chart (it
            // feeds the dental readiness), and whenever that observer is switched
            // off (`satusehat.candidate.auto_generate`, which it reads itself).
            // A correction page alone changes nothing SATUSEHAT reads (rule 240 §8).
            $recordChanged = $subject instanceof Odontogram || ($content['fields'] ?? []) !== [] || ($content['diagnoses'] ?? []) !== [];
            if ($recordChanged && (! $sheetRowWritten || ! (bool) config('satusehat.candidate.auto_generate', true))) {
                $this->refreshSatusehatCandidateAfterCommit((int) $subject->clinic_visit_id, $approver);
            }

            $locked = $this->amendments->transition($locked, [
                'status' => RmeAmendment::STATUS_APPLIED,
                'decided_by' => (int) $approver->getKey(),
                'decided_at' => now(),
                'decision_reason' => $note,
                'applied_version_id' => (int) $version->getKey(),
                'applied_version' => (int) $version->version,
            ]);

            $this->auditLog->log(self::AUDIT_ENTITY, (int) $locked->getKey(), self::AUDIT_APPLIED, ['status' => RmeAmendment::STATUS_PENDING], [
                'subject_type' => $locked->subject_type,
                'subject_id' => (int) $locked->subject_id,
                'branch_id' => (int) $locked->branch_id,
                'status' => RmeAmendment::STATUS_APPLIED,
                'requested_by' => (int) $locked->requested_by,
                'base_version' => (int) $locked->base_version,
                'applied_version' => (int) $version->version,
                'applied_version_id' => (int) $version->getKey(),
                'decision_reason_length' => $note !== null ? mb_strlen($note) : 0,
            ] + ($appended !== null ? [
                // Ids and the digest only — never the key or the image (CG-11).
                'correction_page_id' => (int) $appended->getKey(),
                'correction_page_number' => (int) $appended->page_number,
                'correction_page_medical_record_id' => (int) $appended->medical_record_id,
                'correction_page_sha256' => $appended->handwriting_hash,
            ] : []), $approver);

            $this->notifyAfterCommit(
                fn (): iterable => $this->requesterOf($locked),
                'Amandemen RME diterapkan',
                'Permintaan amandemen #'.$locked->getKey().' disetujui dan diterapkan sebagai versi '.$version->version.'.',
                $locked,
            );

            return $locked;
        });
    }

    // ─── Authority (the policy and the pages ask the same predicates) ────────

    /**
     * Why this account may not request an amendment of the subject, or null. An
     * account that may not even read the record gets MESSAGE_NOT_FINAL here (the
     * pages only need "no"); callers that answer it use maySeeSubject() first and
     * answer 404, so the record's state never leaks.
     */
    public function requestRefusal(MedicalRecord|Odontogram $subject, ?User $user): ?string
    {
        if ($user === null || ! $this->maySeeSubject($user, $subject)) {
            return self::MESSAGE_NOT_FINAL;
        }

        if (! $subject->exists || ! $this->isFinal($subject)) {
            return self::MESSAGE_NOT_FINAL;
        }

        if ($user === null || ! $user->can(self::PERMISSION_REQUEST)) {
            return self::MESSAGE_NO_REQUEST_PERMISSION;
        }

        $userId = (int) $user->getKey();
        if ($userId !== $this->doctorOfRecordUserId($subject) && $userId !== (int) ($subject->finalized_by ?? 0)) {
            return self::MESSAGE_NOT_REQUESTER;
        }

        return null;
    }

    /** May this account read the record at all (MedicalRecordPolicy / OdontogramPolicy `view`)? */
    public function maySeeSubject(User $user, MedicalRecord|Odontogram $subject): bool
    {
        return $subject->exists && Gate::forUser($user)->allows('view', $subject);
    }

    /**
     * Ids of the given amendments this account may open — the queue shows a
     * patient's identity only on those rows (an approver may be narrowed by the
     * doctor-patient scope).
     *
     * @param  iterable<RmeAmendment>  $amendments
     * @return list<int>
     */
    public function viewableIds(User $user, iterable $amendments): array
    {
        $ids = [];
        foreach ($amendments as $amendment) {
            if ($this->mayView($user, $amendment)) {
                $ids[] = (int) $amendment->getKey();
            }
        }

        return $ids;
    }

    /** For the request form: refuses (403) unless the server would accept a request now. */
    public function assertMayRequest(MedicalRecord|Odontogram $subject, User $user): void
    {
        $this->assertVisibleSubject($subject, $user);

        $refusal = $this->requestRefusal($subject, $user)
            ?? ($this->amendments->pendingFor(...$this->subjectKey($subject)) !== null ? self::MESSAGE_PENDING_EXISTS : null);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }
    }

    /** May this account read the amendment (its requester, or an approver in scope)? */
    public function mayView(User $user, RmeAmendment $amendment, MedicalRecord|Odontogram|null $subject = null): bool
    {
        $subject ??= $this->amendments->findSubject($amendment->subject_type, (int) $amendment->subject_id);

        if ($subject === null) {
            return false;
        }

        $isRequester = (int) $amendment->requested_by === (int) $user->getKey();
        $isApprover = $user->can(self::PERMISSION_APPROVE) && $this->branchScope->allows($user, (int) $amendment->branch_id);

        return ($isRequester || $isApprover) && Gate::forUser($user)->allows('view', $subject);
    }

    /**
     * What this account may do with the amendment now — the controls the detail
     * page renders (rule 135): approve also needs the base to be the latest version
     * and the record's version chain to verify — the same two refusals approve()
     * makes (re-review: the page offered "Setujui dan Terapkan" on a broken chain
     * and showed its unverified base as "before" without a warning).
     *
     * @return array{can_cancel: bool, can_reject: bool, can_approve: bool, stale: bool, chain_broken: bool}
     */
    public function actionsFor(RmeAmendment $amendment, User $user, MedicalRecord|Odontogram $subject): array
    {
        $canDecide = $user->can(self::PERMISSION_APPROVE)
            && $this->inApproverScope($user, $amendment, $subject)
            && $this->decisionRefusal($amendment, $subject, $user) === null;
        $latest = $this->versions->latestFor(...$this->subjectKey($subject));
        $stale = $amendment->isPending() && (int) ($latest?->getKey() ?? 0) !== (int) $amendment->base_version_id;
        // Only a pending amendment is compared with the chain it would extend.
        $chainBroken = $amendment->isPending() && ! $this->versionService->verifyChain($subject);

        return [
            'can_cancel' => $amendment->isPending() && (int) $amendment->requested_by === (int) $user->getKey(),
            'can_reject' => $canDecide,
            'can_approve' => $canDecide && ! $stale && ! $chainBroken,
            'stale' => $stale,
            'chain_broken' => $chainBroken,
        ];
    }

    // ─── Reads ──────────────────────────────────────────────────────────────

    /**
     * Rule 240 §8 — an amendment's correction page as read for its requester or
     * an approver in scope (otherwise "not found"): HandwritingPayloadGuard's
     * status and, only when it is `ok`, the bytes. Missing, unreadable (a storage
     * fault, review L2) and tampered are told apart; only `ok` is ever served.
     *
     * @return array{status: string, bytes: ?string}
     */
    public function correctionPageImage(RmeAmendment $amendment, User $user): array
    {
        if (! $this->mayView($user, $amendment)) {
            throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $amendment->getKey()]);
        }

        $stored = $amendment->hasCorrectionPage()
            ? HandwritingPayloadGuard::storedPng($amendment->correction_page_path, $amendment->correction_page_sha256)
            : ['status' => HandwritingPayloadGuard::STORED_MISSING, 'bytes' => null];

        if ($amendment->hasCorrectionPage() && $stored['status'] !== HandwritingPayloadGuard::STORED_OK) {
            Log::warning('An RME amendment correction page was not served.', [
                'rme_amendment_id' => (int) $amendment->getKey(),
                'status' => $stored['status'],
            ]);
        }

        return $stored;
    }

    public function find(int $id): ?RmeAmendment
    {
        return $this->amendments->find($id);
    }

    public function subjectOf(RmeAmendment $amendment): MedicalRecord|Odontogram|null
    {
        return $this->amendments->findSubject($amendment->subject_type, (int) $amendment->subject_id);
    }

    /** The supervisor queue: the approver's list scope (CG-05). */
    public function queueFor(User $user, ?string $status, int $perPage = 20): LengthAwarePaginator
    {
        return $this->amendments->paginateQueue($this->branchScope->resolve($user), $status, $perPage);
    }

    /** @return array<string, int> */
    public function statusCountsFor(User $user): array
    {
        return $this->amendments->statusCounts($this->branchScope->resolve($user));
    }

    /**
     * The "Amandemen" block of a final record's page, or null for a draft. The
     * request offer mirrors the service (rule 135): shown only when request()
     * would accept this account now.
     *
     * @return array{subject_type: string, subject_id: int, is_chart: bool, amendment_count: int, pending: ?RmeAmendment, history: Collection<int, RmeAmendment>, can_request: bool, correction_pages: list<string>, viewable: list<int>}|null
     */
    public function panelFor(MedicalRecord|Odontogram $subject, ?User $user): ?array
    {
        if (! $subject->exists || ! $this->isFinal($subject)) {
            return null;
        }

        [$type, $id] = $this->subjectKey($subject);
        $recent = $this->amendments->recentFor($type, $id, 5);
        // One pending at a time, filed after every earlier one closed: the newest.
        $pending = $recent->first(fn (RmeAmendment $amendment): bool => $amendment->isPending());

        return [
            'subject_type' => $type,
            'subject_id' => $id,
            'is_chart' => $subject instanceof Odontogram,
            // "Diamandemen n×": the applied amendments, counted from the versions.
            'amendment_count' => $this->versionService->amendmentCount($subject),
            'pending' => $pending,
            // The closed ones, newest first (the pending one is shown on its own).
            'history' => $recent->reject(fn (RmeAmendment $amendment): bool => $amendment->isPending())->values(),
            'can_request' => $pending === null && $this->requestRefusal($subject, $user) === null,
            // Rule 240 §8 (review M3) — where this sheet's correction pages sit in
            // the book, as this viewer may read them (§9.5).
            'correction_pages' => $subject instanceof MedicalRecord ? $this->correctionPointersFor($user, $subject) : [],
            'viewable' => $user === null ? [] : $recent
                ->filter(fn (RmeAmendment $amendment): bool => $this->mayView($user, $amendment, $subject))
                ->map(fn (RmeAmendment $amendment): int => (int) $amendment->getKey())
                ->values()
                ->all(),
        ];
    }

    /**
     * What the request form starts from: the record's current content.
     *
     * @return array<string, mixed>
     */
    public function requestFormFor(MedicalRecord|Odontogram $subject): array
    {
        if ($subject instanceof Odontogram) {
            return [
                'summary_notes' => $subject->summary_notes,
                'additional_conditions' => $subject->additional_conditions,
                'teeth' => (array) (($subject->tooth_map_payload ?? [])['teeth'] ?? []),
            ];
        }

        $diagnoses = $this->amendments->sheetDiagnoses((int) $subject->getKey());
        $masters = $this->amendments->clinicalDiagnoses($diagnoses->pluck('clinical_diagnosis_id')->map(fn ($v) => (int) $v)->all());

        return [
            'fields' => collect(self::SHEET_FIELDS)->map(fn (string $label, string $field): array => [
                'label' => $label,
                'value' => $subject->{$field},
            ])->all(),
            'diagnoses' => $diagnoses->map(fn (MedicalRecordDiagnosis $diagnosis): array => [
                'id' => (int) $diagnosis->getKey(),
                'label' => $this->diagnosisLabel($masters->get((int) $diagnosis->clinical_diagnosis_id)),
                'role' => $diagnosis->diagnosis_role,
            ])->values()->all(),
            // Rule 240 §8 — the pages of the patient's book a correction may name:
            // only those the amended encounter wrote (review M2), by book number.
            'book_pages' => $this->encounterBookPages($subject),
        ];
    }

    /**
     * The detail page: the version the request was made against beside the
     * proposal, and what this account may do.
     *
     * @return array<string, mixed>
     */
    public function detailFor(RmeAmendment $amendment, User $user): array
    {
        $amendment->loadMissing(['branch:id,name', 'clinicVisit:id,patient_id,visit_number,visit_date', 'clinicVisit.patient:id,name,medical_record_number', 'requester:id,name', 'decider:id,name', 'baseVersion']);
        $subject = $this->subjectOf($amendment);
        $before = $amendment->baseVersion?->snapshot ?? [];
        $proposed = $amendment->proposed_content ?? [];
        $latest = $this->versions->latestFor($amendment->subject_type, (int) $amendment->subject_id);

        $detail = [
            'amendment' => $amendment,
            'subject' => $subject,
            'latest_version' => $latest?->version,
            'actions' => $subject !== null ? $this->actionsFor($amendment, $user, $subject) : ['can_cancel' => false, 'can_reject' => false, 'can_approve' => false, 'stale' => false, 'chain_broken' => false],
            'correction_page' => $this->correctionPageDetail($amendment),
            // Rule 240 §6.11 — information only; no action above consults it.
            'original_ptm' => $this->originalPtmFor($amendment),
        ];

        // Rule 135 — a correction page that fails its integrity check would be
        // refused by approve(), so the approval is not offered.
        if ($detail['correction_page'] !== null && ! $detail['correction_page']['intact'] && $amendment->isPending()) {
            $detail['actions']['can_approve'] = false;
        }

        if ($amendment->subject_type === RmeRecordVersion::SUBJECT_ODONTOGRAM) {
            return $detail + [
                'chart_diff' => $this->chartDiff($before, $proposed),
            ];
        }

        $beforeDiagnoses = collect($before['diagnoses'] ?? [])->keyBy('id');
        $added = $proposed['diagnoses']['add'] ?? [];
        $masters = $this->amendments->clinicalDiagnoses(array_map(fn (array $row): int => (int) $row['clinical_diagnosis_id'], $added));

        return $detail + [
            'field_rows' => collect($proposed['fields'] ?? [])->map(fn (?string $after, string $field): array => [
                'label' => self::SHEET_FIELDS[$field] ?? $field,
                'before' => $before['fields'][$field] ?? null,
                'after' => $after,
            ])->values()->all(),
            'diagnoses_before' => $beforeDiagnoses->map(fn (array $row): string => $this->snapshotDiagnosisLabel($row))->values()->all(),
            'diagnoses_removed' => collect($proposed['diagnoses']['remove'] ?? [])
                ->map(fn (int $id): string => $beforeDiagnoses->has($id) ? $this->snapshotDiagnosisLabel($beforeDiagnoses->get($id)) : 'Diagnosis #'.$id)
                ->all(),
            'diagnoses_added' => array_map(fn (array $row): string => $this->diagnosisLabel($masters->get((int) $row['clinical_diagnosis_id']))
                .' ('.$this->roleLabel((string) $row['diagnosis_role']).')'
                .(($row['notes'] ?? null) !== null ? ' — '.$row['notes'] : ''), $added),
        ];
    }

    /**
     * Rule 240 §6.11 (owner decision 2026-11-17) — the amended visit's recorded
     * PTM, for INFORMATION only. An amendment corrects a record that already
     * exists, so it stays outside the PTM gate (rule 241 §2.3): request(),
     * cancel(), reject(), approve() and actionsFor() never consult it, and
     * nothing here creates or changes a consent row. `executed` and
     * `patient_only` (the patient's signature, no doctor countersignature) are the
     * live consent's (RmeVisitConsentService::ptmStateFor()); with no live
     * consent, a voided one in the visit's history is `voided`, none at all
     * `none`.
     *
     * @return array{state: string, label: string, note: string}|null  null when the visit is gone
     */
    private function originalPtmFor(RmeAmendment $amendment): ?array
    {
        $visit = $amendment->clinicVisit;

        if ($visit === null) {
            return null;
        }

        $state = match ($this->consents->ptmStateFor($visit)) {
            RmeVisitConsentService::PTM_EXECUTED => self::ORIGINAL_PTM_EXECUTED,
            RmeVisitConsentService::PTM_AWAITING_DOCTOR => self::ORIGINAL_PTM_PATIENT_ONLY,
            default => $this->consents->historyFor($visit)->isEmpty() ? self::ORIGINAL_PTM_NONE : self::ORIGINAL_PTM_VOIDED,
        };

        return ['state' => $state, 'label' => self::ORIGINAL_PTM_LABELS[$state], 'note' => self::ORIGINAL_PTM_NOTE];
    }

    /**
     * The version history of a record (rule 240 §7): read like the record
     * itself — 404 for an account that may not see it.
     *
     * @return array<string, mixed>
     */
    public function versionHistoryFor(User $user, MedicalRecord|Odontogram $subject): array
    {
        $this->assertVisibleSubject($subject, $user);

        Gate::forUser($user)->authorize('view', $subject);
        [$type, $id] = $this->subjectKey($subject);

        // The list needs no snapshot: number, kind, author and time only, the
        // authors eager-loaded (review LOW). The chain is verified on the
        // version page (versionDetailFor) and before every apply (approve()).
        return [
            'subject' => $subject,
            'isChart' => $subject instanceof Odontogram,
            'versions' => $this->amendments->versionSummaries($type, $id),
            'amendmentCount' => $this->versionService->amendmentCount($subject),
        ];
    }

    /**
     * One version beside the one before it. A sheet: its fields, diagnoses,
     * emergency overrides and locked pages — a page only as a link to the
     * existing policy-gated, no-store page route, never its object key. A chart:
     * the PR4 tooth-by-tooth diff against the previous version.
     *
     * @return array<string, mixed>
     */
    public function versionDetailFor(User $user, MedicalRecord|Odontogram $subject, int $number): array
    {
        $this->assertVisibleSubject($subject, $user);
        $version = $this->versionService->version($user, $subject, $number);

        if ($version === null) {
            throw (new ModelNotFoundException)->setModel(RmeRecordVersion::class, [$number]);
        }

        $previous = $number > 1 ? $this->versionService->version($user, $subject, $number - 1) : null;
        $snapshot = $version->snapshot ?? [];
        $before = $previous?->snapshot ?? [];
        $detail = [
            'subject' => $subject,
            'isChart' => $subject instanceof Odontogram,
            'version' => $version,
            'previous' => $previous,
            // Re-review — a reader sees whether this record's history verifies.
            'chainVerified' => $this->versionService->verifyChain($subject),
        ];

        if ($subject instanceof Odontogram) {
            return $detail + ['chart_diff' => $this->chartDiff($before, $snapshot)];
        }

        // Rule 240 §8 / §9.5 — the appended correction pages' labels, as this
        // reader may read them: one query for every amendment the snapshot names.
        $pageLabels = $this->correctionPageLabelsFor($user, array_values(array_unique(array_map(
            fn (array $page): int => (int) $page['rme_amendment_id'],
            array_filter($snapshot['pages'] ?? [], fn (array $page): bool => isset($page['rme_amendment_id'])),
        ))));

        return $detail + [
            'fields' => collect(self::SHEET_FIELDS)->map(fn (string $label, string $field): array => [
                'label' => $label,
                'value' => $snapshot['fields'][$field] ?? null,
                'changed' => $previous !== null && ($snapshot['fields'][$field] ?? null) !== ($before['fields'][$field] ?? null),
            ])->values()->all(),
            'diagnoses' => array_map(fn (array $row): string => $this->snapshotDiagnosisLabel($row), $snapshot['diagnoses'] ?? []),
            'overrides' => array_map(fn (array $row): array => [
                'reason' => $row['reason'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
            ], $snapshot['diagnosis_overrides'] ?? []),
            'pages' => array_map(fn (array $page): array => [
                'medical_record_id' => (int) ($page['medical_record_id'] ?? 0),
                'page_number' => (int) ($page['page_number'] ?? 0),
                // Page 1 lives in the handwriting table, pages 2+ in the page table.
                'url' => (int) ($page['page_number'] ?? 0) === 1
                    ? route('rme.handwritings.image', (int) $page['row_id'])
                    : route('rme.handwriting-pages.image', (int) $page['row_id']),
                'sha256' => isset($page['sha256']) ? substr((string) $page['sha256'], 0, 12) : null,
                // Rule 240 §8 — an appended correction page says which amendment wrote it.
                'label' => isset($page['rme_amendment_id']) ? ($pageLabels[(int) $page['rme_amendment_id']] ?? null) : null,
            ], $snapshot['pages'] ?? []),
        ];
    }

    // ─── Correction page labels per viewer (rule 240 §9.5) ──────────────────

    /**
     * Rule 240 §9.5 (memo M3.3 PR7) — the label of each applied correction page
     * among $amendmentIds as THIS viewer may read it, keyed by amendment id. The
     * full §8.5 label (the amended visit's number and date, the approver, the
     * amendment's number) only when the viewer may open the amended visit through
     * its own policy (`ClinicVisitPolicy::view`: the working-branch read scope,
     * the active-room rule and, with the encounter-scope flag ON, the doctor
     * scope); otherwise NEUTRAL_CORRECTION_PAGE_LABEL. R7-1 keeps one book per
     * patient across branches, so the page itself (its image) stays visible.
     * One query, and one policy check per distinct amended visit.
     *
     * @param  list<int>  $amendmentIds
     * @return array<int, string>
     */
    public function correctionPageLabelsFor(?User $viewer, array $amendmentIds): array
    {
        $mayOpen = [];
        $labels = [];

        foreach ($this->amendments->appliedCorrectionPageAmendments($amendmentIds) as $id => $amendment) {
            $label = $amendment->correctionPageLabel();

            if ($label === null) {
                continue;
            }

            $mayOpen[(int) $amendment->clinic_visit_id] ??= $this->mayOpenAmendedVisit($viewer, $amendment->clinicVisit);
            $labels[(int) $id] = $mayOpen[(int) $amendment->clinic_visit_id] ? $label : self::NEUTRAL_CORRECTION_PAGE_LABEL;
        }

        return $labels;
    }

    /**
     * Rule 240 §9.5 — handwriting page entries (MedicalRecord::orderedHandwritingPages()
     * or the workspace book) with `amendment_label` set on each correction page as
     * this viewer may read it (null when its amendment is not applied).
     *
     * @param  Collection<int|string, array<string, mixed>>  $pages
     * @return Collection<int|string, array<string, mixed>>
     */
    public function correctionPagesFor(?User $viewer, Collection $pages): Collection
    {
        $labels = $this->correctionPageLabelsFor($viewer, $pages
            ->pluck('amendment_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all());

        return $pages->map(fn (array $page): array => isset($page['amendment_id'])
            ? ['amendment_label' => $labels[(int) $page['amendment_id']] ?? null] + $page
            : $page);
    }

    /**
     * Rule 240 §9.5 — the amended sheet's pointers to its correction pages
     * (MedicalRecord::correctionPagePointers(): "Halaman koreksi: buku RM halaman
     * N (amandemen #id)") as this viewer may read them: each pointer's amended
     * visit is the sheet's own, so one policy check decides them all; a viewer
     * who may not open it reads NEUTRAL_CORRECTION_PAGE_LABEL for each.
     *
     * @return list<string>
     */
    public function correctionPointersFor(?User $viewer, MedicalRecord $sheet): array
    {
        $pointers = $sheet->correctionPagePointers();

        if ($pointers === [] || $this->mayOpenAmendedVisit($viewer, $sheet->clinicVisit)) {
            return $pointers;
        }

        return array_fill(0, count($pointers), self::NEUTRAL_CORRECTION_PAGE_LABEL);
    }

    /**
     * Rule 240 §9.5 — a sheet's RM pages as the RME print ($embedBinary) or the
     * cashier's clinical summary shows them, with its correction pointers, both
     * as this viewer may read their labels. The views print these as given.
     *
     * @return array{rmPagesForReader: Collection<int|string, array<string, mixed>>, correctionPointersForReader: list<string>}
     */
    public function sheetPagesFor(?User $viewer, ?MedicalRecord $sheet, bool $embedBinary): array
    {
        if ($sheet === null) {
            return ['rmPagesForReader' => collect(), 'correctionPointersForReader' => []];
        }

        return [
            'rmPagesForReader' => $this->correctionPagesFor($viewer, $sheet->orderedHandwritingPages($embedBinary)
                ->filter(fn (array $page): bool => (bool) $page['has_content'])),
            'correctionPointersForReader' => $this->correctionPointersFor($viewer, $sheet),
        ];
    }

    /** May this viewer open the amended visit — the visit page's own ability? */
    private function mayOpenAmendedVisit(?User $viewer, ?ClinicVisit $visit): bool
    {
        return $viewer !== null && $visit !== null && Gate::forUser($viewer)->allows('view', $visit);
    }

    // ─── Internals ──────────────────────────────────────────────────────────

    /**
     * Rule 240 §8 — the correction page a request carries, decoded and validated
     * before anything is written: a sheet only; the handwriting canvas decoder
     * (payload, PNG, declared size and inflate bounds, the blank guard) and a
     * clean PNG structure. Null when none is given (then no page may be named).
     */
    private function correctionPageInput(MedicalRecord|Odontogram $subject, ?string $canvas, ?int $correctsPageNumber): ?string
    {
        if ($canvas === null || $canvas === '') {
            if ($correctsPageNumber !== null) {
                throw $this->refusal('corrects_page_number', self::MESSAGE_CORRECTS_PAGE_WITHOUT_PAGE);
            }

            return null;
        }

        if ($subject instanceof Odontogram) {
            throw $this->refusal('handwriting_data', self::MESSAGE_CORRECTION_PAGE_SHEET_ONLY);
        }

        $png = $this->canvasGuard->decodeCanvas($canvas);
        $this->canvasGuard->assertCleanPng($png);

        return $png;
    }

    /**
     * Rule 240 §8 — the stored correction object as approve() applies it: present,
     * its SHA-256 the recorded one, and still a canvas today's rules accept.
     */
    private function verifiedCorrectionPage(RmeAmendment $amendment): string
    {
        $stored = HandwritingPayloadGuard::storedPng($amendment->correction_page_path, $amendment->correction_page_sha256);

        // Review L2 — a read fault is retried, never reported as tampering.
        if ($stored['status'] === HandwritingPayloadGuard::STORED_UNREADABLE) {
            throw $this->refusal('amendment', self::MESSAGE_CORRECTION_PAGE_UNREADABLE);
        }

        $bytes = $stored['bytes'];
        if ($bytes === null) {
            throw $this->refusal('amendment', self::MESSAGE_CORRECTION_PAGE_INTEGRITY);
        }

        try {
            $this->canvasGuard->assertCleanPng($this->canvasGuard->decodeCanvas('data:image/png;base64,'.base64_encode($bytes)));
        } catch (ValidationException) {
            throw $this->refusal('amendment', self::MESSAGE_PROPOSAL_CHANGED);
        }

        return $bytes;
    }

    /**
     * The patient's handwriting book (R7-1): the record new pages are written
     * on. The amended sheet itself when the patient has no book in the RME
     * branches (a sheet whose branch left RME).
     */
    private function bookOf(MedicalRecord $sheet): MedicalRecord
    {
        return $this->workspace->canonicalMedicalRecord((int) $sheet->patient_id) ?? $sheet;
    }

    /**
     * The named page holds a stored drawing in this book (page 1: the legacy row;
     * 2+: the pages table) — `$missing` otherwise — and the amended encounter
     * wrote it (review M2) — `$foreign` otherwise.
     */
    private function assertBookPage(MedicalRecord $book, MedicalRecord $sheet, int $pageNumber, string $key, string $missing, string $foreign): void
    {
        if ($pageNumber < 1 || $pageNumber > self::PAGE_NUMBER_MAX) {
            throw $this->refusal($key, $missing);
        }

        $row = $pageNumber === 1
            ? $this->handwritings->findByMedicalRecordId((int) $book->getKey())
            : $this->handwritings->findPageByMedicalRecordIdAndPage((int) $book->getKey(), $pageNumber);

        if ($row === null || blank($row->handwriting_path)) {
            throw $this->refusal($key, $missing);
        }

        if (! $this->writtenByEncounterOf($row, $sheet)) {
            throw $this->refusal($key, $foreign);
        }
    }

    /**
     * Review M2 — did the amended sheet's encounter write this page? A stamped
     * page: its `authored_clinic_visit_id` is the sheet's visit. A page from
     * before rule 240 (no author stamp): only when the amended sheet sealed it.
     */
    private function writtenByEncounterOf(MedicalRecordHandwriting|MedicalRecordHandwritingPage $row, MedicalRecord $sheet): bool
    {
        if ($row->authored_clinic_visit_id !== null) {
            return (int) $row->authored_clinic_visit_id === (int) $sheet->clinic_visit_id;
        }

        return (int) ($row->sealed_by_medical_record_id ?? 0) === (int) $sheet->getKey();
    }

    /**
     * The request form's list: the book pages with a stored drawing that the
     * amended encounter wrote, numbered as the book numbers them.
     *
     * @return list<array{number: int, label: string}>
     */
    private function encounterBookPages(MedicalRecord $sheet): array
    {
        $book = $this->bookOf($sheet);
        $rows = collect([1 => $book->latestHandwriting()]);
        foreach ($book->handwritingPages()->get() as $page) {
            $rows->put((int) $page->page_number, $page);
        }

        return $rows
            ->filter(fn ($row): bool => $row !== null && filled($row->handwriting_path) && $this->writtenByEncounterOf($row, $sheet))
            ->map(fn ($row, int $number): array => [
                'number' => $number,
                'label' => 'Halaman buku RM '.$number.($row->getAttribute('rme_amendment_id') !== null ? ' (halaman koreksi)' : ''),
            ])
            ->values()
            ->all();
    }

    /** A fresh server key on the private clinical disk; the client never supplies one. */
    private function correctionObjectKey(MedicalRecord $sheet): string
    {
        return sprintf(
            'handwritings/%d/%d/amendment_%s_%s.png',
            (int) $sheet->branch_id,
            (int) $sheet->clinic_visit_id,
            now()->format('YmdHis'),
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * Best effort, rule 224: the key is this call's own and no committed row
     * names it, so a failed removal costs one unreferenced private object.
     */
    private function discardUncommitted(string $key): void
    {
        try {
            ClinicalEvidenceStorage::disk()->delete($key);
        } catch (Throwable $e) {
            Log::warning('An uncommitted clinical evidence object could not be removed.', ['type' => $e::class]);
        }
    }

    /**
     * Rule 240 §8 — the correction page on the amendment's own page: a link to
     * the policy-gated image route (never the key), its digest, whether the
     * stored object still verifies, and — once applied — the book page it became.
     *
     * `state` is HandwritingPayloadGuard's read status (review L2: a read fault
     * is not tampering).
     *
     * @return array{url: string, sha256: string, corrects_page_number: ?int, state: string, intact: bool, applied: bool, page_number: ?int, label: ?string}|null
     */
    private function correctionPageDetail(RmeAmendment $amendment): ?array
    {
        if (! $amendment->hasCorrectionPage()) {
            return null;
        }

        $applied = $amendment->status === RmeAmendment::STATUS_APPLIED;
        $page = $applied ? $this->handwritings->findAmendmentPage((int) $amendment->getKey()) : null;
        $state = HandwritingPayloadGuard::storedPng($amendment->correction_page_path, $amendment->correction_page_sha256)['status'];

        return [
            'url' => route('rme.amendments.correction-page', $amendment),
            'sha256' => substr((string) $amendment->correction_page_sha256, 0, 12),
            'corrects_page_number' => $amendment->corrects_page_number,
            'state' => $state,
            'intact' => $state === HandwritingPayloadGuard::STORED_OK,
            'applied' => $applied,
            'page_number' => $page !== null ? (int) $page->page_number : null,
            'label' => $page !== null ? $amendment->correctionPageLabel() : null,
        ];
    }

    /**
     * The stored proposal, re-validated against the locked record under today's
     * rules; refused unless it still says exactly what was requested.
     *
     * @return array<string, mixed>
     */
    private function revalidated(RmeAmendment $amendment, MedicalRecord|Odontogram $subject): array
    {
        $stored = (array) $amendment->proposed_content;

        try {
            $content = $subject instanceof Odontogram
                ? $this->chartProposal($subject, $stored)
                : $this->sheetProposal($subject, $stored, allowEmpty: $amendment->hasCorrectionPage());
        } catch (ValidationException $e) {
            throw $this->refusal('amendment', collect($e->errors())->flatten()->implode(' '));
        }

        if ($content !== $stored) {
            throw $this->refusal('amendment', self::MESSAGE_PROPOSAL_CHANGED);
        }

        return $content;
    }

    /**
     * As MedicalRecordDiagnosisService::refreshCandidate(): after commit, the
     * visit's existing candidate only (none is created here), failures logged
     * with ids only and never thrown into the caller.
     */
    private function refreshSatusehatCandidateAfterCommit(int $clinicVisitId, User $actor): void
    {
        DB::afterCommit(function () use ($clinicVisitId, $actor): void {
            try {
                $candidate = $this->amendments->satusehatCandidateForVisit($clinicVisitId);
                if ($candidate !== null) {
                    app(SatusehatCandidateService::class)->refresh($candidate, $actor);
                }
            } catch (Throwable $e) {
                Log::warning('SATUSEHAT candidate refresh failed after an RME amendment was applied', [
                    'clinic_visit_id' => $clinicVisitId,
                    'error' => $e::class,
                ]);
            }
        });
    }

    /**
     * After commit, never inside the transaction, and never into the caller: the
     * amendment already stands, a failed notification changes nothing.
     *
     * @param  Closure(): iterable<User>  $recipients
     */
    private function notifyAfterCommit(Closure $recipients, string $title, string $message, RmeAmendment $amendment): void
    {
        $amendmentId = (int) $amendment->getKey();

        DB::afterCommit(function () use ($recipients, $title, $message, $amendmentId): void {
            try {
                $url = route('rme.amendments.show', $amendmentId, false);

                foreach ($recipients() as $recipient) {
                    try {
                        $recipient->notify(new RmeAmendmentNotification($title, $message, $url, $amendmentId));
                    } catch (Throwable $e) {
                        Log::warning('RME amendment notification failed; the amendment stands.', [
                            'rme_amendment_id' => $amendmentId,
                            'recipient_id' => (int) $recipient->getKey(),
                            'error' => $e::class,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('RME amendment notification routing failed; the amendment stands.', [
                    'rme_amendment_id' => $amendmentId,
                    'error' => $e::class,
                ]);
            }
        });
    }

    /**
     * The usable accounts that could decide this amendment now: the approve
     * permission, the scope, and none of the separation-of-duties refusals.
     *
     * @return list<User>
     */
    private function approversFor(RmeAmendment $amendment, MedicalRecord|Odontogram $subject): array
    {
        $candidates = $this->amendments->approverCandidates(self::PERMISSION_APPROVE);
        if ($candidates->isEmpty()) {
            return [];
        }

        // Another account's working-branch scope may expire its stale online
        // context as a side effect; read it in a transaction that always rolls
        // back, as RmeBillingDisputeEscalationService does.
        DB::beginTransaction();

        try {
            return $candidates
                ->filter(fn (User $user): bool => $this->accounts->isUsable($user)
                    && $this->inApproverScope($user, $amendment, $subject)
                    && $this->decisionRefusal($amendment, $subject, $user) === null)
                ->values()
                ->all();
        } finally {
            DB::rollBack();
        }
    }

    /** @return list<User> */
    private function requesterOf(RmeAmendment $amendment): array
    {
        $requester = $amendment->requester()->first();

        return $requester instanceof User && $this->accounts->isUsable($requester) ? [$requester] : [];
    }

    private function assertRequester(MedicalRecord|Odontogram $subject, User $user): void
    {
        $this->assertVisibleSubject($subject, $user);
        $refusal = $this->requestRefusal($subject, $user);

        if ($refusal !== null) {
            throw $this->refusal('amendment', $refusal);
        }
    }

    /** A record this account may not read is "not found" — before any other answer. */
    private function assertVisibleSubject(MedicalRecord|Odontogram $subject, User $user): void
    {
        if (! $this->maySeeSubject($user, $subject)) {
            throw (new ModelNotFoundException)->setModel($subject::class, [$subject->getKey()]);
        }
    }

    /**
     * Why this account may not decide the amendment now, or null: state,
     * permission, then separation of duties — not the requester, not the doctor
     * of record, not the finalizer (CG-07; binds Super Admin).
     */
    public function decisionRefusal(RmeAmendment $amendment, MedicalRecord|Odontogram $subject, User $user): ?string
    {
        if (! $amendment->isPending()) {
            return self::MESSAGE_NOT_PENDING;
        }

        if (! $user->can(self::PERMISSION_APPROVE)) {
            return self::MESSAGE_NO_APPROVE_PERMISSION;
        }

        if ((int) $amendment->requested_by === (int) $user->getKey()) {
            return self::MESSAGE_SELF_DECISION;
        }

        if ($this->doctorOfRecordUserId($subject) === (int) $user->getKey()) {
            return self::MESSAGE_DOCTOR_OF_RECORD;
        }

        // Creator ≠ approver (CG-07): whoever finalized the record never decides a
        // correction of it. Decided on the locked subject inside reject().
        if ((int) ($subject->finalized_by ?? 0) === (int) $user->getKey()) {
            return self::MESSAGE_FINALIZER;
        }

        return null;
    }

    private function inApproverScope(User $user, RmeAmendment $amendment, MedicalRecord|Odontogram $subject): bool
    {
        return $this->branchScope->allows($user, (int) $amendment->branch_id)
            && Gate::forUser($user)->allows('view', $subject);
    }

    /**
     * Rule 240 §1.4 extended: the subject's visit (FOR NO KEY UPDATE), then the
     * subject row; returns the subject as it is now.
     */
    private function lockSubject(MedicalRecord|Odontogram $subject): MedicalRecord|Odontogram
    {
        $this->visits->lockForStatusDecision((int) $subject->clinic_visit_id);

        $locked = $subject instanceof Odontogram
            ? $this->odontograms->findByClinicVisitForUpdate((int) $subject->clinic_visit_id)
            : $this->medicalRecords->lockForHandwriting((int) $subject->getKey());

        if ($locked === null || (int) $locked->getKey() !== (int) $subject->getKey()) {
            throw (new ModelNotFoundException)->setModel($subject::class, [$subject->getKey()]);
        }

        return $locked;
    }

    /** @return array{0: RmeAmendment, 1: MedicalRecord|Odontogram} visit → subject → amendment */
    private function lockForDecision(RmeAmendment $amendment): array
    {
        $subject = $this->subjectOf($amendment);

        if ($subject === null) {
            throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $amendment->getKey()]);
        }

        $lockedSubject = $this->lockSubject($subject);
        $locked = $this->amendments->findForUpdate((int) $amendment->getKey());

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(RmeAmendment::class, [(int) $amendment->getKey()]);
        }

        return [$locked, $lockedSubject];
    }

    /**
     * @param  bool  $allowEmpty  rule 240 §8 — a correction page is the change
     * @return array<string, mixed> only what changes; empty only when allowed
     */
    private function sheetProposal(MedicalRecord $sheet, array $proposed, bool $allowEmpty = false): array
    {
        $this->assertKeys($proposed, ['fields', 'diagnoses'], 'proposed');
        $fields = $this->arrayPart($proposed, 'fields');
        $this->assertKeys($fields, array_keys(self::SHEET_FIELDS), 'proposed.fields');

        $changed = [];
        foreach (array_keys(self::SHEET_FIELDS) as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            if ($fields[$field] !== null && ! is_string($fields[$field])) {
                throw $this->refusal("proposed.fields.$field", 'Isi kolom yang diusulkan tidak valid.');
            }

            $value = $this->text($fields[$field]);
            // TrimStrings trims what the form posts; whitespace around a text is
            // not a change (compared trimmed on both sides).
            if ($this->comparable($value) === $this->comparable($sheet->{$field})) {
                continue;
            }

            // Bounded only when it changes: a longer text already on the sheet,
            // sent back unchanged, is not part of the proposal.
            if ($value !== null && mb_strlen($value) > self::SHEET_FIELD_MAX) {
                throw $this->refusal("proposed.fields.$field", 'Isi kolom yang diusulkan terlalu panjang (maksimal '.self::SHEET_FIELD_MAX.' karakter).');
            }

            $changed[$field] = $value;
        }

        $diagnoses = $this->diagnosisProposal($sheet, $this->arrayPart($proposed, 'diagnoses'));
        $content = array_filter(['fields' => $changed, 'diagnoses' => $diagnoses], fn (array $part): bool => $part !== []);

        if ($content === [] && ! $allowEmpty) {
            throw $this->refusal('proposed', self::MESSAGE_NO_CHANGE);
        }

        return $content;
    }

    /** @return array{add?: list<array{clinical_diagnosis_id: int, diagnosis_role: string, notes: ?string}>, remove?: list<int>} */
    private function diagnosisProposal(MedicalRecord $sheet, array $input): array
    {
        $this->assertKeys($input, ['add', 'remove'], 'proposed.diagnoses');
        $current = $this->amendments->sheetDiagnoses((int) $sheet->getKey());

        $remove = [];
        foreach ($this->arrayPart($input, 'remove') as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw $this->refusal('proposed.diagnoses.remove', self::MESSAGE_DIAGNOSIS_NOT_ON_SHEET);
            }
            if (! $current->has((int) $id)) {
                throw $this->refusal('proposed.diagnoses.remove', self::MESSAGE_DIAGNOSIS_NOT_ON_SHEET);
            }
            $remove[(int) $id] = (int) $id;
        }
        $remove = array_values($remove);

        $rows = [];
        foreach ($this->arrayPart($input, 'add') as $row) {
            if (! is_array($row)) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_DIAGNOSIS_ROW);
            }
            $this->assertKeys($row, self::DIAGNOSIS_ROW_KEYS, 'proposed.diagnoses.add');

            // Types first, never a cast of a malformed value (an array, '12abc').
            $notes = $row['notes'] ?? null;
            if ($notes !== null && ! is_string($notes)) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_DIAGNOSIS_ROW);
            }

            // The form always posts one add row; an untouched one names no diagnosis.
            $masterId = $row['clinical_diagnosis_id'] ?? null;
            if ($masterId === null || $masterId === '') {
                if ($this->text($notes) !== null) {
                    throw $this->refusal('proposed.diagnoses.add', 'Pilih diagnosis untuk catatan yang diusulkan.');
                }

                continue;
            }

            if (! is_int($masterId) && ! (is_string($masterId) && ctype_digit($masterId))) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_DIAGNOSIS_ROW);
            }

            if (array_key_exists('diagnosis_role', $row) && ! is_string($row['diagnosis_role'])) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_DIAGNOSIS_ROLE);
            }

            $rows[] = $row;
        }

        if (count($rows) > self::DIAGNOSIS_ADD_MAX) {
            throw $this->refusal('proposed.diagnoses.add', 'Paling banyak '.self::DIAGNOSIS_ADD_MAX.' diagnosis baru per amandemen.');
        }

        $kept = $current->reject(fn (MedicalRecordDiagnosis $diagnosis): bool => in_array((int) $diagnosis->getKey(), $remove, true));
        $masters = $this->amendments->clinicalDiagnoses(array_values(array_unique(array_merge(
            array_map(fn (array $row): int => (int) $row['clinical_diagnosis_id'], $rows),
            $kept->map(fn (MedicalRecordDiagnosis $diagnosis): int => (int) $diagnosis->clinical_diagnosis_id)->values()->all(),
        ))));
        $onSheet = $kept->map(fn (MedicalRecordDiagnosis $diagnosis): int => (int) $diagnosis->clinical_diagnosis_id)->values()->all();
        $primaries = $kept->where('diagnosis_role', MedicalRecordDiagnosis::ROLE_PRIMARY)->count();
        // DiagnosisRolloutService::hasActivePrimaryDiagnosis() counts a primary whose master is ACTIVE.
        $activePrimaries = $kept
            ->filter(fn (MedicalRecordDiagnosis $diagnosis): bool => $diagnosis->diagnosis_role === MedicalRecordDiagnosis::ROLE_PRIMARY
                && $masters->get((int) $diagnosis->clinical_diagnosis_id)?->status === ClinicalDiagnosis::STATUS_ACTIVE)
            ->count();

        $add = [];
        foreach ($rows as $row) {
            $masterId = (int) $row['clinical_diagnosis_id'];
            $master = $masters->get($masterId);
            if (! $master instanceof ClinicalDiagnosis || $master->status !== ClinicalDiagnosis::STATUS_ACTIVE) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_MASTER_INACTIVE);
            }

            $role = $row['diagnosis_role'] ?? '';
            if (! in_array($role, MedicalRecordDiagnosis::ROLES, true)) {
                throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_DIAGNOSIS_ROLE);
            }

            if (in_array($masterId, $onSheet, true)) {
                throw $this->refusal('proposed.diagnoses.add', 'Diagnosis ini sudah tercatat pada rekam medis.');
            }

            $notes = $row['notes'] ?? null;
            if ($notes !== null && mb_strlen($notes) > 500) {
                throw $this->refusal('proposed.diagnoses.add', 'Catatan diagnosis maksimal 500 karakter.');
            }

            $onSheet[] = $masterId;
            $isPrimary = $role === MedicalRecordDiagnosis::ROLE_PRIMARY;
            $primaries += $isPrimary ? 1 : 0;
            $activePrimaries += $isPrimary ? 1 : 0;
            $add[] = ['clinical_diagnosis_id' => $masterId, 'diagnosis_role' => $role, 'notes' => $this->text($notes)];
        }

        if ($primaries > 1) {
            throw $this->refusal('proposed.diagnoses.add', self::MESSAGE_SECOND_PRIMARY);
        }

        $change = array_filter(['add' => $add, 'remove' => $remove], fn (array $part): bool => $part !== []);

        // The state after the change must pass the finalization gate the sheet
        // passed (DiagnosisRolloutService::assertFinalizationAllowed(): on a
        // pilot_enforced branch, an active primary or a usable override). M3.3
        // PR5 re-checks it when applying.
        if ($change !== []
            && $activePrimaries === 0
            && $this->diagnosisRollout->modeForBranch((int) $sheet->branch_id) === DiagnosisRolloutSetting::MODE_PILOT_ENFORCED
            && ! $this->diagnosisRollout->hasUsableOverride($sheet)) {
            throw $this->refusal('proposed.diagnoses', self::MESSAGE_PRIMARY_REQUIRED);
        }

        return $change;
    }

    /**
     * The whole proposed chart. Validated with the rules of a chart save
     * (UpdateOdontogramPlaceholderRequest is the source of truth for the fields and
     * their bounds), plus: only `teeth` in the tooth map, only the known per-tooth
     * keys, FDI numbers, no duplicate condition. An absent key keeps the current
     * value; an explicit null or an unreadable tooth map is refused — it cannot be
     * told apart from a broken editor, and an approval must never erase a chart
     * from it (an empty chart is `{"teeth": {}}`).
     *
     * @return array{summary_notes: ?string, additional_conditions: ?string, tooth_map_payload: mixed}
     */
    private function chartProposal(Odontogram $chart, array $proposed): array
    {
        $this->assertKeys($proposed, ['summary_notes', 'additional_conditions', 'tooth_map_payload'], 'proposed');

        if (array_key_exists('tooth_map_payload', $proposed)) {
            if (is_string($proposed['tooth_map_payload'])) {
                $decoded = json_decode($proposed['tooth_map_payload'], true);
                // An unreadable string stays a string, so the `array` rule refuses it.
                $proposed['tooth_map_payload'] = is_array($decoded) ? $decoded : $proposed['tooth_map_payload'];
            }

            // Never erase a chart from a null (a chart that has no tooth map yet
            // keeps none — that is no change).
            if ($proposed['tooth_map_payload'] === null && $chart->tooth_map_payload !== null) {
                throw $this->refusal('proposed.tooth_map_payload', self::MESSAGE_TOOTH_MAP);
            }
        }

        // The chart AFTER the change is what is validated (review LOW): an absent
        // key keeps the current value, and that value must pass today's rules too,
        // or a request could be accepted that no approval could ever apply.
        $merged = [
            'summary_notes' => array_key_exists('summary_notes', $proposed) ? $proposed['summary_notes'] : $chart->summary_notes,
            'additional_conditions' => array_key_exists('additional_conditions', $proposed) ? $proposed['additional_conditions'] : $chart->additional_conditions,
            'tooth_map_payload' => array_key_exists('tooth_map_payload', $proposed) ? $proposed['tooth_map_payload'] : $chart->tooth_map_payload,
        ];

        $rules = [];
        foreach ((new UpdateOdontogramPlaceholderRequest)->rules() as $key => $rule) {
            $rules['proposed.'.$key] = $rule;
        }
        $toothKeys = $this->toothKeys();
        $conditionValues = $this->conditionValues($rules);

        $validator = Validator::make(['proposed' => $merged], $rules);
        $validator->after(function ($v) use ($merged, $toothKeys, $conditionValues): void {
            $map = $merged['tooth_map_payload'] ?? null;
            if (! is_array($map)) {
                return;
            }

            if (array_diff(array_keys($map), ['teeth']) !== []) {
                $v->errors()->add('proposed.tooth_map_payload', self::MESSAGE_TOOTH_MAP_PART);
            }

            // A tooth map always names its teeth; an empty chart is `{"teeth": {}}`.
            // `{"teeth": null}` or `{}` would read as "every tooth removed" — clearing
            // a whole chart is not an amendment intent.
            $teeth = $map['teeth'] ?? null;
            if (! is_array($teeth)) {
                $v->errors()->add('proposed.tooth_map_payload', self::MESSAGE_TOOTH_MAP);

                return;
            }

            $valid = FdiToothNumbers::all();
            foreach ($teeth as $tooth => $entry) {
                if (! in_array((string) $tooth, $valid, true)) {
                    $v->errors()->add('proposed.tooth_map_payload.teeth', self::MESSAGE_TOOTH_NUMBER);
                }

                if (! is_array($entry) || array_diff(array_keys($entry), $toothKeys) !== []) {
                    $v->errors()->add("proposed.tooth_map_payload.teeth.{$tooth}", self::MESSAGE_TOOTH_FIELD);
                }

                // As the editor writes them (resources/js/app.js): a list of distinct
                // known conditions — never a map, never a null, at most one of each.
                if (is_array($entry) && array_key_exists('conditions', $entry) && $entry['conditions'] !== null) {
                    $conditions = $entry['conditions'];
                    if (! is_array($conditions)
                        || ! array_is_list($conditions)
                        || in_array(null, $conditions, true)
                        // Only strings reach array_unique(): a nested array would raise
                        // "Array to string conversion" (a 500) instead of this 422.
                        || count(array_filter($conditions, 'is_string')) !== count($conditions)
                        || count($conditions) > count($conditionValues)
                        || count($conditions) !== count(array_unique($conditions))) {
                        $v->errors()->add("proposed.tooth_map_payload.teeth.{$tooth}.conditions", self::MESSAGE_CONDITIONS);
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $current = [
            'summary_notes' => $this->text($chart->summary_notes),
            'additional_conditions' => $this->text($chart->additional_conditions),
            'tooth_map_payload' => $chart->tooth_map_payload,
        ];
        $after = [
            'summary_notes' => $this->text($merged['summary_notes']),
            'additional_conditions' => $this->text($merged['additional_conditions']),
            'tooth_map_payload' => $merged['tooth_map_payload'],
        ];

        // "Changes nothing" is judged on what the reviewer sees (chartDiff()), so an
        // editor that only adds empty fields is not a proposal.
        $diff = $this->chartDiff($current, $after);
        if (! collect($diff['rows'])->contains('changed', true) && ! collect($diff['notes'])->contains('changed', true)) {
            throw $this->refusal('proposed', self::MESSAGE_NO_CHANGE);
        }

        return $after;
    }

    /**
     * The condition values a chart save accepts (the `in:` list of its
     * `teeth.*.conditions.*` rule) — so their number bounds a tooth's list.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function conditionValues(array $rules): array
    {
        foreach ((array) ($rules['proposed.tooth_map_payload.teeth.*.conditions.*'] ?? []) as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'in:')) {
                return explode(',', substr($rule, 3));
            }
        }

        return [];
    }

    /** @return list<string> the per-tooth keys a chart save validates */
    private function toothKeys(): array
    {
        $keys = [];
        foreach (array_keys((new UpdateOdontogramPlaceholderRequest)->rules()) as $rule) {
            if (preg_match('/^tooth_map_payload\.teeth\.\*\.([a-z_]+)/', $rule, $m) === 1) {
                $keys[$m[1]] = $m[1];
            }
        }

        return array_values($keys);
    }

    /**
     * Before and after of a chart, tooth by tooth: every field each side stores
     * (a key a save no longer writes is still shown, by name), and whether the
     * rendered tooth differs.
     *
     * @return array{rows: list<array{tooth: string, before: list<string>, after: list<string>, changed: bool}>, notes: list<array{label: string, before: ?string, after: ?string, changed: bool}>}
     */
    private function chartDiff(array $before, array $after): array
    {
        $teethBefore = $this->teethOf($before);
        $teethAfter = $this->teethOf($after);
        $teeth = array_values(array_unique(array_merge(array_keys($teethBefore), array_keys($teethAfter))));
        usort($teeth, fn ($a, $b): int => strnatcmp((string) $a, (string) $b));

        $rows = [];
        foreach ($teeth as $tooth) {
            $lineBefore = $this->toothLines($teethBefore[$tooth] ?? null);
            $lineAfter = $this->toothLines($teethAfter[$tooth] ?? null);
            $rows[] = ['tooth' => (string) $tooth, 'before' => $lineBefore, 'after' => $lineAfter, 'changed' => $lineBefore !== $lineAfter];
        }

        $notes = [];
        foreach (['summary_notes' => 'Ringkasan', 'additional_conditions' => 'Kondisi tambahan'] as $key => $label) {
            $from = $this->text($before[$key] ?? null);
            $to = $this->text($after[$key] ?? null);
            $notes[] = ['label' => $label, 'before' => $from, 'after' => $to, 'changed' => $this->comparable($from) !== $this->comparable($to)];
        }

        return ['rows' => $rows, 'notes' => $notes];
    }

    /** @return array<string, mixed> keyed by tooth number (string) */
    private function teethOf(array $chart): array
    {
        $teeth = $chart['tooth_map_payload']['teeth'] ?? [];
        $out = [];
        foreach (is_array($teeth) ? $teeth : [] as $tooth => $entry) {
            $out[(string) $tooth] = $entry;
        }

        return $out;
    }

    /** @return list<string> one "Label: value" line per non-empty field, known fields first */
    private function toothLines(mixed $entry): array
    {
        if ($entry === null) {
            return [];
        }

        if (! is_array($entry)) {
            return [(string) json_encode($entry, JSON_UNESCAPED_UNICODE)];
        }

        $lines = [];
        $unknown = array_diff(array_keys($entry), array_keys(self::TOOTH_FIELD_LABELS));
        sort($unknown);
        foreach ([...array_keys(self::TOOTH_FIELD_LABELS), ...$unknown] as $key) {
            $value = $entry[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $text = is_array($value)
                ? implode(', ', array_map(fn ($item): string => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE), $value))
                : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE));
            $lines[] = (self::TOOTH_FIELD_LABELS[$key] ?? $key).': '.$text;
        }

        return $lines;
    }

    private function doctorOfRecordUserId(MedicalRecord|Odontogram $subject): ?int
    {
        $doctorId = $subject instanceof Odontogram ? $subject->clinicVisit?->doctor_id : $subject->doctor_id;

        return $this->amendments->doctorUserId($doctorId !== null ? (int) $doctorId : null);
    }

    /** The patient's live encounter, whose own unsealed pages the baseline does not count as locked (rule 240 §5.4). */
    private function liveEncounterId(MedicalRecord|Odontogram $subject): ?int
    {
        if ($subject instanceof Odontogram) {
            return null;
        }

        $encounter = $this->encounters->currentFor((int) $subject->patient_id);

        return $encounter !== null ? (int) $encounter->getKey() : null;
    }

    private function isFinal(MedicalRecord|Odontogram $subject): bool
    {
        return $subject instanceof Odontogram
            ? $subject->isFinalized()
            : $subject->status === MedicalRecord::STATUS_FINAL;
    }

    /** @return array{0: string, 1: int} */
    private function subjectKey(MedicalRecord|Odontogram $subject): array
    {
        return [
            $subject instanceof Odontogram ? RmeRecordVersion::SUBJECT_ODONTOGRAM : RmeRecordVersion::SUBJECT_MEDICAL_RECORD,
            (int) $subject->getKey(),
        ];
    }

    private function boundedReason(string $reason, string $key, string $message): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < RmeAmendment::REASON_MIN || $length > RmeAmendment::REASON_MAX) {
            throw $this->refusal($key, $message);
        }

        return $reason;
    }

    /** A textarea value as stored: LF line endings, empty is null. */
    /** A text as compared for "did it change": line endings normalised, surrounding whitespace ignored. */
    private function comparable(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : trim($text);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($value) === '' ? null : $value;
    }

    /** @param list<string> $allowed */
    private function assertKeys(array $input, array $allowed, string $key): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw $this->refusal($key, self::MESSAGE_UNKNOWN_PART);
        }
    }

    /** @return array<array-key, mixed> */
    private function arrayPart(array $input, string $key): array
    {
        $part = $input[$key] ?? [];

        if (! is_array($part)) {
            throw $this->refusal('proposed', 'Usulan amandemen tidak valid.');
        }

        return $part;
    }

    private function diagnosisLabel(?ClinicalDiagnosis $master): string
    {
        return $master !== null ? trim($master->code.' — '.$master->display) : 'Diagnosis tidak dikenal';
    }

    /** @param array<string, mixed> $row */
    private function snapshotDiagnosisLabel(array $row): string
    {
        return trim(($row['code'] ?? '').' — '.($row['display'] ?? '')).' ('.$this->roleLabel((string) ($row['diagnosis_role'] ?? '')).')';
    }

    private function roleLabel(string $role): string
    {
        return $role === MedicalRecordDiagnosis::ROLE_PRIMARY ? 'utama' : 'sekunder';
    }

    private function refusal(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }

    private function isPendingUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['23505', '23000'], true)
            && (str_contains($message, 'trx_rme_amd_one_pending_uq') || str_contains($message, 'trx_rme_amendments.subject_id'));
    }
}
