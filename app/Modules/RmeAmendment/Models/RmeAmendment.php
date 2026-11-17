<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicOversight\Support\OversightFormat;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — a supervised
 * amendment request of a final sheet or of a finalized odontogram.
 *
 * `pending` → `cancelled` (by the requester), `rejected` (by a checker who is
 * not the requester, not the doctor of record and not the finalizer, with a
 * written reason) or `applied` (M3.3 PR5, approve-and-apply in one
 * transaction). The subject is named by `subject_type`
 * (RmeRecordVersion::SUBJECT_*) and `subject_id`; the branch and visit are
 * copied from the subject by the service, never taken from a request. The
 * patient is read through the visit (`clinicVisit.patient`): a patient merge
 * reassigns the visit, so the amendment follows the surviving patient.
 *
 * Neither `status` nor the decision columns are mass-assignable: a new row is
 * always `pending` (RmeAmendmentRepository::create()), and only
 * RmeAmendmentRepository::transition() moves it. The request itself (subject,
 * base version, reason, proposal, requester) never changes, and a row that left
 * `pending` never changes at all; the row is never deleted. This model refuses
 * all three; on PostgreSQL the trigger `trx_rme_amendments_guard` refuses them
 * for raw SQL too.
 *
 * Rule 240 §8 (M3.3 PR6) — a sheet amendment may carry ONE handwriting
 * correction page: its object key on the private clinical disk
 * (`correction_page_path`, chosen by the server and never serialised with the
 * row), the SHA-256 of its bytes and, optionally, the page of the patient's book
 * it corrects. All three are part of the request and never change.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $branch_id
 * @property int $clinic_visit_id
 * @property int $base_version_id
 * @property int $base_version
 * @property string $status
 * @property string $reason
 * @property array<string, mixed> $proposed_content
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 * @property string|null $correction_page_path
 * @property string|null $correction_page_sha256
 * @property int|null $corrects_page_number
 */
class RmeAmendment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    /** Written by M3.3 PR5 (approve and apply). */
    public const STATUS_APPLIED = 'applied';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_APPLIED];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu persetujuan',
        self::STATUS_CANCELLED => 'Dibatalkan pengaju',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_APPLIED => 'Diterapkan',
    ];

    public const SUBJECT_LABELS = [
        RmeRecordVersion::SUBJECT_MEDICAL_RECORD => 'Rekam Medis',
        RmeRecordVersion::SUBJECT_ODONTOGRAM => 'Odontogram',
    ];

    /** Bounds of the requester's reason and of the checker's decision reason. */
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    /** What was requested: fixed from the moment the row exists. */
    public const REQUEST_COLUMNS = [
        'subject_type',
        'subject_id',
        'branch_id',
        'clinic_visit_id',
        'base_version_id',
        'base_version',
        'reason',
        'proposed_content',
        'requested_by',
        'created_at',
        // Rule 240 §8 — the correction page is part of the request.
        'correction_page_path',
        'correction_page_sha256',
        'corrects_page_number',
    ];

    protected $table = 'trx_rme_amendments';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'branch_id',
        'clinic_visit_id',
        'base_version_id',
        'base_version',
        'reason',
        'proposed_content',
        'requested_by',
        'correction_page_path',
        'correction_page_sha256',
        'corrects_page_number',
    ];

    /** The object key never leaves the server (rule 121 §3): not in arrays, JSON or logs of the row. */
    protected $hidden = ['correction_page_path'];

    protected static function booted(): void
    {
        static::updating(function (RmeAmendment $amendment): void {
            if ($amendment->getOriginal('status') !== self::STATUS_PENDING) {
                throw new LogicException(sprintf('RME amendment #%d is decided and can no longer change.', (int) $amendment->getKey()));
            }

            foreach (self::REQUEST_COLUMNS as $column) {
                if ($amendment->isDirty($column)) {
                    throw new LogicException(sprintf('The request of RME amendment #%d is immutable (%s).', (int) $amendment->getKey(), $column));
                }
            }
        });

        static::deleting(function (RmeAmendment $amendment): void {
            throw new LogicException(sprintf('RME amendment #%d can never be deleted.', (int) $amendment->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'base_version_id' => 'integer',
            'base_version' => 'integer',
            'proposed_content' => 'array',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'corrects_page_number' => 'integer',
            'cancelled_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Rule 240 §8 — does this amendment carry a handwriting correction page? */
    public function hasCorrectionPage(): bool
    {
        return filled($this->correction_page_path) && filled($this->correction_page_sha256);
    }

    /**
     * Rule 240 §8 — how an applied correction page is labelled wherever it is
     * shown (workspace, print, version history, oversight): the amendment, the
     * encounter it amends (visit number and clinic date), the book page it
     * corrects when one was named, and when (WITA) and by whom it was approved
     * (review M3). Reads `clinicVisit` and `decider`; callers eager-load them.
     * Null until it is applied.
     */
    public function correctionPageLabel(): ?string
    {
        if ($this->status !== self::STATUS_APPLIED || ! $this->hasCorrectionPage()) {
            return null;
        }

        $visit = $this->clinicVisit;
        $encounter = $visit !== null
            ? sprintf('kunjungan %s (%s WITA)', $visit->visit_number ?: '#'.$visit->getKey(), $visit->visit_date?->format('d/m/Y') ?? '—')
            : 'kunjungan #'.(int) $this->clinic_visit_id;

        return sprintf(
            'Halaman koreksi — amandemen #%d untuk %s%s; disetujui %s WITA oleh %s',
            (int) $this->getKey(),
            $encounter,
            $this->corrects_page_number !== null ? ', mengoreksi halaman buku RM '.(int) $this->corrects_page_number : '',
            OversightFormat::clinicalTime($this->decided_at, 'd/m/Y H:i') ?? '—',
            $this->decider?->name ?? '—',
        );
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function subjectLabel(): string
    {
        return self::SUBJECT_LABELS[$this->subject_type] ?? $this->subject_type;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The subject's visit — and through it the patient (`clinicVisit.patient`):
     * the amendment carries no patient column, so a patient merge, which
     * reassigns the visit, carries the amendment along (rule 240 §6.1).
     */
    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class)->withTrashed();
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(RmeRecordVersion::class, 'base_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
