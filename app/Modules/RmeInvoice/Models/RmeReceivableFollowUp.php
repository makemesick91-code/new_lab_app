<?php

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\RmeReceivableFollowUpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation.
// Tracking only: recording a follow-up never mutates invoice/payment status.
class RmeReceivableFollowUp extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'NEW';

    public const STATUS_CONTACTED = 'CONTACTED';

    public const STATUS_PROMISED = 'PROMISED';

    public const STATUS_FOLLOW_UP_LATER = 'FOLLOW_UP_LATER';

    public const STATUS_ESCALATED = 'ESCALATED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_PROMISED,
        self::STATUS_FOLLOW_UP_LATER,
        self::STATUS_ESCALATED,
        self::STATUS_CLOSED,
    ];

    public const CHANNEL_WHATSAPP = 'WHATSAPP';

    public const CHANNEL_PHONE = 'PHONE';

    public const CHANNEL_IN_PERSON = 'IN_PERSON';

    public const CHANNEL_OTHER = 'OTHER';

    public const CHANNELS = [
        self::CHANNEL_WHATSAPP,
        self::CHANNEL_PHONE,
        self::CHANNEL_IN_PERSON,
        self::CHANNEL_OTHER,
    ];

    protected $table = 'trx_rme_receivable_follow_ups';

    protected $fillable = [
        'rme_invoice_id',
        'branch_id',
        'user_id',
        'status',
        'channel',
        'contacted_at',
        'next_follow_up_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'rme_invoice_id' => 'integer',
            'branch_id' => 'integer',
            'user_id' => 'integer',
            'contacted_at' => 'datetime',
            'next_follow_up_date' => 'date',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): RmeReceivableFollowUpFactory
    {
        return RmeReceivableFollowUpFactory::new();
    }
}
