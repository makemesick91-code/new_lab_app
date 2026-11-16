<?php

namespace App\Modules\PaymentMethod\Models;

use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPES = ['cash', 'bank_transfer', 'qris', 'card', 'other'];

    public const TYPE_BANK_TRANSFER = 'bank_transfer';

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (rule 238 §3, memo M5.1) — a payment
     * by bank transfer must carry the transfer's reference number.
     */
    public const MESSAGE_TRANSFER_REFERENCE_REQUIRED = 'Nomor referensi transfer wajib diisi untuk pembayaran Transfer Bank.';

    protected $table = 'mst_payment_methods';

    protected $fillable = [
        'code',
        'name',
        'type',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function requiresTransferReference(): bool
    {
        return $this->type === self::TYPE_BANK_TRANSFER;
    }

    /**
     * Rule 238 §3 — decided from the stored method record, never from anything
     * the client sends about the method.
     */
    public static function idRequiresTransferReference(mixed $paymentMethodId): bool
    {
        if (! is_numeric($paymentMethodId)) {
            return false;
        }

        return (bool) self::query()->whereKey((int) $paymentMethodId)->first()?->requiresTransferReference();
    }

    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }
}
