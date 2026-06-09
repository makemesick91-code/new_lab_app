<?php

namespace Database\Seeders;

use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 — default clinic rooms for the active/default (MAIN) branch.
 * Idempotent: updateOrCreate keyed by (branch_id, code).
 */
class ClinicRoomSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, type: string, description: string|null}>
     */
    public const ROOMS = [
        ['code' => 'RM-P1',  'name' => 'Ruang Periksa 1',   'type' => ClinicRoom::TYPE_TREATMENT_ROOM,     'description' => null],
        ['code' => 'RM-P2',  'name' => 'Ruang Periksa 2',   'type' => ClinicRoom::TYPE_TREATMENT_ROOM,     'description' => null],
        ['code' => 'RM-TND', 'name' => 'Ruang Tindakan',    'type' => ClinicRoom::TYPE_TREATMENT_ROOM,     'description' => null],
        ['code' => 'RM-KSL', 'name' => 'Ruang Konsultasi',  'type' => ClinicRoom::TYPE_CONSULTATION_ROOM,  'description' => null],
        ['code' => 'RM-STR', 'name' => 'Ruang Sterilisasi', 'type' => ClinicRoom::TYPE_STERILIZATION_ROOM, 'description' => null],
    ];

    public function run(): void
    {
        $branchId = app(BranchContext::class)->requireId();

        foreach (self::ROOMS as $room) {
            ClinicRoom::updateOrCreate(
                ['branch_id' => $branchId, 'code' => $room['code']],
                [
                    'name' => $room['name'],
                    'type' => $room['type'],
                    'status' => ClinicRoom::STATUS_ACTIVE,
                    'description' => $room['description'],
                ],
            );
        }
    }
}
