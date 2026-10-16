<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — a frozen invoice / receipt
 * document version (audit BCF-06).
 *
 * Written once when a correction is APPLIED and never updated or deleted. A
 * reprint of a historical version renders this row's snapshot, never the live
 * invoice. "Superseded" is derived (a higher version exists), never stored.
 */
class RmeInvoiceDocumentVersion extends Model
{
    public const KIND_ORIGINAL = 'ORIGINAL';

    public const KIND_REVISION = 'REVISION';

    /** v0 was captured from the locked live rows just before the first correction. */
    public const BASIS_PRE_FIRST_CORRECTION = 'PRE_FIRST_CORRECTION_LIVE_STATE';

    /** vN was captured inside the transaction that applied correction N. */
    public const BASIS_APPLIED_CORRECTION = 'APPLIED_CORRECTION';

    protected $table = 'trx_rme_invoice_document_versions';

    protected $fillable = [
        'uuid',
        'rme_invoice_id',
        'branch_id',
        'version_number',
        'version_kind',
        'document_reference',
        'source_correction_id',
        'capture_basis',
        'snapshot_schema_version',
        'snapshot',
        'snapshot_sha256',
        'issued_by',
        'issued_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeInvoiceDocumentVersion $version): void {
            throw new LogicException(sprintf('RME invoice document version #%d is immutable.', (int) $version->getKey()));
        });

        static::deleting(function (RmeInvoiceDocumentVersion $version): void {
            throw new LogicException(sprintf('RME invoice document version #%d can never be deleted.', (int) $version->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_invoice_id' => 'integer',
            'branch_id' => 'integer',
            'version_number' => 'integer',
            'source_correction_id' => 'integer',
            'snapshot_schema_version' => 'integer',
            'snapshot' => 'array',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceCorrection(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceCorrection::class, 'source_correction_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isOriginal(): bool
    {
        return $this->version_kind === self::KIND_ORIGINAL;
    }

    /**
     * The stored snapshot still hashes to the value recorded when it was frozen.
     */
    public function integrityHolds(): bool
    {
        $snapshot = $this->snapshot;

        return is_array($snapshot)
            && hash_equals((string) $this->snapshot_sha256, RmeInvoiceSnapshot::documentHash($snapshot));
    }

    public function label(): string
    {
        return $this->isOriginal()
            ? 'Versi Asli (v0)'
            : 'Revisi ke-'.$this->version_number.' (v'.$this->version_number.')';
    }
}
