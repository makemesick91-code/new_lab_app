<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 27 Phase 27.2 — migrate legacy patient ID prefixes RM → DG.
 *
 * Safe rules:
 * - Only rows whose medical_record_number starts with "RM DG-" or "RM-" (non DG variant).
 * - Skip rows already starting with "DG".
 * - Skip rows that would collide with an existing DG-prefixed number.
 * - Idempotent: re-running leaves DG-prefixed rows unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('mst_patients')
            ->whereNotNull('medical_record_number')
            ->where(function ($query) {
                $query->where('medical_record_number', 'like', 'RM DG-%')
                    ->orWhere(function ($nested) {
                        $nested->where('medical_record_number', 'like', 'RM-%')
                            ->where('medical_record_number', 'not like', 'RM DG-%');
                    });
            })
            ->orderBy('id')
            ->get(['id', 'medical_record_number']);

        foreach ($rows as $row) {
            $newNumber = $this->mapPrefix((string) $row->medical_record_number);

            if ($newNumber === null || $newNumber === $row->medical_record_number) {
                continue;
            }

            $collision = DB::table('mst_patients')
                ->where('medical_record_number', $newNumber)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($collision) {
                continue;
            }

            DB::table('mst_patients')
                ->where('id', $row->id)
                ->update(['medical_record_number' => $newNumber]);
        }
    }

    public function down(): void
    {
        $rows = DB::table('mst_patients')
            ->whereNotNull('medical_record_number')
            ->where('medical_record_number', 'like', 'DG-%')
            ->orderBy('id')
            ->get(['id', 'medical_record_number']);

        foreach ($rows as $row) {
            $legacyNumber = $this->unmapPrefix((string) $row->medical_record_number);

            if ($legacyNumber === null || $legacyNumber === $row->medical_record_number) {
                continue;
            }

            $collision = DB::table('mst_patients')
                ->where('medical_record_number', $legacyNumber)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($collision) {
                continue;
            }

            DB::table('mst_patients')
                ->where('id', $row->id)
                ->update(['medical_record_number' => $legacyNumber]);
        }
    }

    private function mapPrefix(string $number): ?string
    {
        if (str_starts_with($number, 'RM DG-')) {
            return 'DG-'.substr($number, 6);
        }

        if (str_starts_with($number, 'RM-')) {
            return 'DG'.substr($number, 2);
        }

        return null;
    }

    private function unmapPrefix(string $number): ?string
    {
        if (! str_starts_with($number, 'DG-')) {
            return null;
        }

        $suffix = substr($number, 3);

        if (preg_match('/^[A-Z0-9]+-\d{4}-/', $suffix) === 1) {
            return 'RM DG-'.$suffix;
        }

        return 'RM-'.$suffix;
    }
};
