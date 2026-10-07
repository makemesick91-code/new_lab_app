<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Services\PatientMergeCaseService;
use App\Modules\PatientMerge\Services\PatientMergeExecutionService;
use App\Modules\PatientMerge\Support\PatientMergeField;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — shared fixtures for the
 * PatientMerge suites. Required (not autoloaded) by each suite.
 */

if (! function_exists('pmBranch')) {
    function pmBranch(string $code = 'TLK1', string $name = 'Cabang Telkomas'): Branch
    {
        return Branch::query()->where('code', $code)->first()
            ?? Branch::factory()->create(['code' => $code, 'name' => $name, 'is_active' => true, 'is_rme_enabled' => true]);
    }

    function pmPatient(array $attributes = [], ?Branch $branch = null): Patient
    {
        static $sequence = 5000;
        $sequence++;
        $branch ??= pmBranch();

        return Patient::factory()->create($attributes + [
            'branch_id' => $branch->id,
            'medical_record_number' => sprintf('DG-%s-2025-%05d', $branch->code, $sequence),
            'ktp_number' => null,
        ]);
    }

    function pmUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** A case opened by $requester with every field resolved and $canonical chosen. */
    function pmReadyCase(Patient $a, Patient $b, User $requester, ?Patient $canonical = null, array $overrides = []): PatientMergeCase
    {
        $service = app(PatientMergeCaseService::class);
        $case = $service->create($requester, $a->id, $b->id, 'Pasien terdaftar dua kali saat registrasi.');

        $fields = [];

        foreach ($case->fieldResolutions as $resolution) {
            if ($resolution->source !== null) {
                continue;
            }

            $fields[$resolution->field] = ['source' => $a->getAttribute($resolution->field) !== null && $a->getAttribute($resolution->field) !== ''
                ? PatientMergeField::SOURCE_A
                : PatientMergeField::SOURCE_B];
        }

        return $service->resolve($case, $requester, array_merge($fields, $overrides), ($canonical ?? $a)->id);
    }

    function pmSubmittedCase(Patient $a, Patient $b, User $requester, ?Patient $canonical = null, array $overrides = []): PatientMergeCase
    {
        return app(PatientMergeCaseService::class)->submit(pmReadyCase($a, $b, $requester, $canonical, $overrides), $requester);
    }

    function pmMerge(PatientMergeCase $case, User $approver): PatientMergeCase
    {
        return app(PatientMergeExecutionService::class)->approveAndMerge($case, $approver, 'Diverifikasi dengan KTP asli.');
    }
}
