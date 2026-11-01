<?php

/*
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — shared fixtures of the
| "Tindakan Dilakukan" suites. Every helper is prefixed `dpt`. They build on the
| zero-value (`rzv*`) and invoice-correction (`ric*`) fixtures so the suites
| exercise the REAL clinical, invoice, closure and correction services.
*/

use App\Models\User;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeZeroValueClosure/helpers.php';

if (! function_exists('dptSetup')) {
    /**
     * The flag key contains a dot, so the whole `feature_flags.flags` array is
     * rewritten; `env_value` is cleared so a captured override never outranks
     * the test's intent.
     */
    function dptFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags[RmePerformedTreatmentFeatureGuard::FLAG]['default'] = $enabled;
        $flags[RmePerformedTreatmentFeatureGuard::FLAG]['env_value'] = null;
        config()->set('feature_flags.flags', $flags);
    }

    /**
     * The zero-value fixture (branch, patient, doctor master, cashier, Konsultasi
     * Dokter, voucher) plus: a treatment room, the doctor's account linked to the
     * doctor record and online in that room, a lab treatment, a reserved Kontrol
     * service, and the capability flag ON.
     */
    function dptSetup(): void
    {
        rzvSetup();
        ricTariffs(test()->branch);

        $t = test();
        $restorative = TreatmentCategory::query()->firstOrCreate(['code' => 'restorative'], ['name' => 'Restoratif']);
        $control = TreatmentCategory::query()->firstOrCreate(['code' => 'control'], ['name' => 'Kontrol']);

        $t->treatment->update(['treatment_category_id' => $restorative->id]);
        $t->treatment2->update(['treatment_category_id' => $restorative->id]);
        $t->scaling = $t->treatment->refresh();
        $t->filling = $t->treatment2->refresh();
        $t->labTreatment = Treatment::factory()->create([
            'code' => 'TRT-DPT-CROWN', 'name' => 'Mahkota Gigi', 'treatment_category_id' => $restorative->id,
            'is_active' => true, 'requires_lab' => true,
        ]);
        $t->controlService = Treatment::factory()->create([
            'code' => 'TRT-DPT-KONTROL', 'name' => 'Kontrol', 'treatment_category_id' => $control->id,
            'is_active' => true, 'requires_lab' => false,
        ]);

        $t->room = ClinicRoom::factory()->create(['branch_id' => $t->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
        $t->doctorUser = rmeMakeDoctorOnline($t->doctor, $t->branch, $t->room);

        dptFlag(true);
    }

    /**
     * An authorized, consented, in_progress encounter treated by the fixture
     * doctor in the fixture room — the state the clinic is in after "Mulai
     * Pemeriksaan" and the signed Persetujuan Tindakan Medis.
     *
     * @param  array<string, mixed>  $attributes
     */
    function dptEncounter(array $attributes = []): ClinicVisit
    {
        $visit = ClinicVisit::factory()->create($attributes + [
            'branch_id' => test()->branch->id,
            'clinic_id' => test()->clinic->id,
            'patient_id' => test()->patient->id,
            'doctor_id' => test()->doctor->id,
            'clinic_room_id' => test()->room->id,
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-DPT-'.uniqid(),
            'visit_date' => clinicalToday()->toDateString(),
        ]);

        return rmeActiveConsentedEncounter($visit);
    }

    function dptService(): RmePerformedTreatmentService
    {
        return app(RmePerformedTreatmentService::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    function dptRecord(ClinicVisit $visit, Treatment $treatment, array $extra = [], ?User $actor = null): RmePerformedTreatment
    {
        $actor ??= test()->doctorUser;
        test()->actingAs($actor);

        return dptService()->record($visit->fresh(), $extra + [
            'treatment_id' => $treatment->id,
            'quantity' => 1,
            'tooth_numbers' => [],
            'submission_token' => (string) Str::uuid(),
        ], $actor);
    }

    function dptDeclare(ClinicVisit $visit, string $type, ?User $actor = null): RmeTreatmentDeclaration
    {
        $actor ??= test()->doctorUser;
        test()->actingAs($actor);

        return dptService()->declare($visit->fresh(), $type, $actor);
    }

    function dptVoid(ClinicVisit $visit, RmePerformedTreatment $row, string $reason = 'Salah pilih tindakan.', ?User $actor = null): RmePerformedTreatment
    {
        $actor ??= test()->doctorUser;
        test()->actingAs($actor);

        return dptService()->void($visit->fresh(), $row->fresh(), $reason, $actor);
    }

    /** The validation messages of a refused call, flattened; null when it succeeded. */
    function dptRefusal(callable $call): ?string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return implode(' | ', array_merge(...array_values($e->errors())));
        }

        return null;
    }

    function dptAudits(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    /**
     * Move an encounter to cashier_pending through the canonical guarded service.
     * The cashier can only bill a FINAL medical record, so the encounter gets one
     * (the doctor finalizes the RME before finishing — the clinic's order).
     */
    function dptCompleteExamination(ClinicVisit $visit, ?User $actor = null): ClinicVisit
    {
        $actor ??= test()->doctorUser;
        test()->actingAs($actor);

        if (! MedicalRecord::query()->where('clinic_visit_id', $visit->id)->exists()) {
            MedicalRecord::factory()->final()->create([
                'clinic_visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
                'patient_id' => $visit->patient_id, 'doctor_id' => $visit->doctor_id,
            ]);
        }

        return app(ClinicVisitService::class)
            ->transitionStatus($visit->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $actor)
            ->refresh();
    }
}
