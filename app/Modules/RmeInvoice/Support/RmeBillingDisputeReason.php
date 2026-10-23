<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * Rule 203 — why a cashier reports an invoice. A closed list so the branch
 * queue and the oversight figures can be grouped without reading free text.
 */
final class RmeBillingDisputeReason
{
    public const AMOUNT_INCORRECT = 'AMOUNT_INCORRECT';

    public const ITEM_INCORRECT = 'ITEM_INCORRECT';

    public const DUPLICATE_CHARGE = 'DUPLICATE_CHARGE';

    public const DISCOUNT_INCORRECT = 'DISCOUNT_INCORRECT';

    public const OTHER = 'OTHER';

    public const LABELS = [
        self::AMOUNT_INCORRECT => 'Nominal / harga tidak sesuai',
        self::ITEM_INCORRECT => 'Item tindakan tidak sesuai',
        self::DUPLICATE_CHARGE => 'Tagihan ganda',
        self::DISCOUNT_INCORRECT => 'Diskon tidak sesuai',
        self::OTHER => 'Lainnya',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $reason): string
    {
        return self::LABELS[$reason] ?? $reason;
    }
}
