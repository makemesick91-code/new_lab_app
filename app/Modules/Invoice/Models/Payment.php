<?php

namespace App\Modules\Invoice\Models;

use App\Models\User;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Payment extends Model
{
    use HasFactory;

    public const ENTITY_TYPE = 'trx_payments';

    public const METHOD_CASH = 'CASH';

    public const METHOD_BANK_TRANSFER = 'BANK_TRANSFER';

    public const METHOD_QRIS = 'QRIS';

    public const METHOD_CARD = 'CARD';

    public const METHOD_OTHER = 'OTHER';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_QRIS,
        self::METHOD_CARD,
        self::METHOD_OTHER,
    ];

    protected $table = 'trx_payments';

    protected $fillable = [
        'payment_number',
        'invoice_id',
        'payment_date',
        'payment_method',
        'amount',
        'reference_number',
        'notes',
        'received_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'entity');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
