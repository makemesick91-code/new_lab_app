<?php

namespace Database\Seeders;

use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 Phase 4 — default payment methods (global master data).
 * Idempotent: updateOrCreate keyed by code, never truncates or deletes.
 */
class PaymentMethodSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, type: string, sort_order: int}>
     */
    public const METHODS = [
        ['code' => 'CASH',          'name' => 'Tunai',         'type' => 'cash',          'sort_order' => 10],
        ['code' => 'BANK_TRANSFER', 'name' => 'Transfer Bank', 'type' => 'bank_transfer', 'sort_order' => 20],
        ['code' => 'QRIS',          'name' => 'QRIS',          'type' => 'qris',          'sort_order' => 30],
        ['code' => 'CARD',          'name' => 'Kartu',         'type' => 'card',          'sort_order' => 40],
        ['code' => 'OTHER',         'name' => 'Lainnya',       'type' => 'other',         'sort_order' => 50],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $method) {
            PaymentMethod::updateOrCreate(
                ['code' => $method['code']],
                [
                    'name' => $method['name'],
                    'type' => $method['type'],
                    'sort_order' => $method['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
