<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — one line of a correction.
 *
 * Written once at submission, never updated or deleted. `operation` and every
 * money column are computed by the server.
 */
class RmeInvoiceCorrectionLine extends Model
{
    public const OP_KEEP = 'KEEP';

    public const OP_CHANGE = 'CHANGE';

    public const OP_ADD = 'ADD';

    public const OP_REMOVE = 'REMOVE';

    public const OPERATIONS = [self::OP_KEEP, self::OP_CHANGE, self::OP_ADD, self::OP_REMOVE];

    public const OPERATION_LABELS = [
        self::OP_KEEP => 'Tetap',
        self::OP_CHANGE => 'Diubah',
        self::OP_ADD => 'Ditambah',
        self::OP_REMOVE => 'Dihapus',
    ];

    protected $table = 'trx_rme_invoice_correction_lines';

    protected $fillable = [
        'rme_invoice_correction_id',
        'line_no',
        'operation',
        'original_item_id',
        'original_treatment_id',
        'original_description',
        'original_qty',
        'original_unit_price',
        'original_discount',
        'original_subtotal',
        'original_doctor_id',
        'proposed_treatment_id',
        'proposed_description',
        'proposed_qty',
        'proposed_unit_price',
        'proposed_discount',
        'proposed_subtotal',
        'proposed_doctor_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeInvoiceCorrectionLine $line): void {
            throw new LogicException(sprintf('RME invoice correction line #%d is immutable.', (int) $line->getKey()));
        });

        static::deleting(function (RmeInvoiceCorrectionLine $line): void {
            throw new LogicException(sprintf('RME invoice correction line #%d can never be deleted.', (int) $line->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_invoice_correction_id' => 'integer',
            'line_no' => 'integer',
            'original_item_id' => 'integer',
            'original_treatment_id' => 'integer',
            'original_qty' => 'integer',
            'original_unit_price' => 'decimal:2',
            'original_discount' => 'decimal:2',
            'original_subtotal' => 'decimal:2',
            'original_doctor_id' => 'integer',
            'proposed_treatment_id' => 'integer',
            'proposed_qty' => 'integer',
            'proposed_unit_price' => 'decimal:2',
            'proposed_discount' => 'decimal:2',
            'proposed_subtotal' => 'decimal:2',
            'proposed_doctor_id' => 'integer',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceCorrection::class, 'rme_invoice_correction_id');
    }

    /** Not a foreign key (the original row is a historical fact), but resolvable for display. */
    public function originalTreatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'original_treatment_id');
    }

    /** Not a foreign key (the original row is a historical fact), but resolvable for display. */
    public function originalDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'original_doctor_id');
    }

    /**
     * True when an existing item's treatment differs from what is proposed. The
     * approver must see this: treatment drives lab-candidate creation after payment.
     */
    public function treatmentChanged(): bool
    {
        return $this->original_item_id !== null
            && $this->proposed_description !== null
            && $this->original_treatment_id !== $this->proposed_treatment_id;
    }

    public function proposedTreatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'proposed_treatment_id');
    }

    public function proposedDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'proposed_doctor_id');
    }

    public function operationLabel(): string
    {
        return self::OPERATION_LABELS[$this->operation] ?? (string) $this->operation;
    }
}
