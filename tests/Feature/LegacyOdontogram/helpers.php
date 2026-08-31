<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FIX-04b — shared fixtures for the legacy odontogram archive suite.
|--------------------------------------------------------------------------
|
| Deliberately NOT added to tests/Pest.php and deliberately NOT reusing the
| legacy RME fixtures: `legacyRmeBranch()` also admits the branch to a legacy
| RME migration wave and rewrites that module's rollout config. This capability
| has no wave, no quota and no admission, so borrowing those fixtures would
| both add unrelated state to every test and quietly couple the two archives —
| the exact coupling the module is built to avoid.
|
| The filename is `helpers.php`, not `*Test.php`, so PHPUnit never mistakes it
| for a test class; each test file requires it explicitly.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramImportService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;

if (! function_exists('lodoFlag')) {
    /**
     * Toggle the legacy odontogram MIGRATION capability inside a test.
     *
     * The flag key itself contains a dot ("rme.legacy_odontogram_archive"), so
     * it is a literal array key and cannot be reached by config dot-notation —
     * the whole `feature_flags.flags` array has to be rewritten.
     */
    function lodoFlag(bool $enabled): void
    {
        $flags = config('feature_flags.flags', []);
        $flags['rme.legacy_odontogram_archive']['default'] = $enabled;

        config()->set('feature_flags.flags', $flags);
    }
}

if (! function_exists('lodoBranch')) {
    /**
     * An active, RME-enabled branch identified by its BUSINESS CODE.
     *
     * The code matters: a legacy odontogram's owning branch is derived from the
     * branch-code segment of the patient's Nomor RM, so a fixture branch has to
     * be reachable by that code.
     */
    function lodoBranch(string $code = 'TLK1', string $name = 'Cabang Telkomas'): Branch
    {
        $branch = Branch::withTrashed()->firstOrNew(['code' => $code]);

        $branch->forceFill([
            'name' => $branch->exists ? $branch->name : $name,
            'is_active' => true,
            'is_rme_enabled' => true,
            'deleted_at' => null,
        ])->save();

        return $branch->refresh();
    }
}

if (! function_exists('lodoPatient')) {
    /**
     * A patient whose Nomor RM resolves to a real RME-enabled branch.
     *
     * `$attributes` is merged on the LEFT so an explicit value always wins —
     * including an explicit `null` date_of_birth, which exercises the
     * "no recorded birth date" path.
     *
     * @param  array<string, mixed>  $attributes
     */
    function lodoPatient(array $attributes = [], string $branchCode = 'TLK1'): Patient
    {
        $branch = lodoBranch($branchCode);

        static $sequence = 0;
        $sequence++;

        return Patient::factory()->create($attributes + [
            'branch_id' => $branch->id,
            'medical_record_number' => sprintf('DG-%s-2024-%04d', $branchCode, $sequence),
            'date_of_birth' => '1990-01-01',
        ]);
    }
}

if (! function_exists('lodoChartedTeeth')) {
    /**
     * A minimal but REAL charted tooth map, in the shape production actually
     * stores: `teeth` is an OBJECT keyed by FDI number, not a list.
     *
     * Verified against the pilot database — every charted row there is
     * `{"teeth": {"11": {"status": "caries", ...}, ...}}`. A fixture that used a
     * JSON array would model a shape the application never writes.
     *
     * @return array<string, mixed>
     */
    function lodoChartedTeeth(string $fdi = '11', string $status = 'caries'): array
    {
        return ['teeth' => [$fdi => [
            'note' => '',
            'status' => $status,
            'conditions' => [],
        ]]];
    }
}

if (! function_exists('lodoNativeOdontogram')) {
    /**
     * Give a patient a NATIVE odontogram carrying REAL clinical content on a
     * given clinical date.
     *
     * The clinical date is the owning VISIT's `visit_date` — the odontogram row
     * itself has no clinical date column, which is exactly why the resolver
     * reads the visit.
     *
     * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 — this fixture now charts a
     * tooth. `OdontogramFactory` defaults `tooth_map_payload` to NULL, so before
     * this sprint every "native odontogram" in this suite was in fact an EMPTY
     * placeholder, and the tests were asserting the cutoff against exactly the
     * rows the cutoff must now ignore. Use `lodoEmptyNativeOdontogram()` when a
     * contentless row is the point of the test.
     */
    function lodoNativeOdontogram(Patient $patient, string $visitDate, ?string $status = null): Odontogram
    {
        return lodoOdontogramRow($patient, $visitDate, lodoChartedTeeth(), $status);
    }
}

if (! function_exists('lodoEmptyNativeOdontogram')) {
    /**
     * A native odontogram row that carries NO clinical content — the placeholder
     * shape that already exists in production and that the native-reference
     * cutoff must not treat as evidence.
     *
     * `$payload` defaults to NULL (the shape the pilot database actually holds);
     * pass `['teeth' => []]` to exercise the empty-map shape that SQL cannot
     * portably exclude.
     *
     * @param  array<string, mixed>|null  $payload
     */
    function lodoEmptyNativeOdontogram(Patient $patient, string $visitDate, ?array $payload = null, ?string $status = null): Odontogram
    {
        return lodoOdontogramRow($patient, $visitDate, $payload, $status);
    }
}

if (! function_exists('lodoOdontogramRow')) {
    /**
     * The one place this suite creates an odontogram + its owning visit.
     *
     * @param  array<string, mixed>|null  $payload
     */
    function lodoOdontogramRow(Patient $patient, string $visitDate, ?array $payload, ?string $visitStatus = null): Odontogram
    {
        $visit = ClinicVisit::factory()->create([
            'patient_id' => $patient->id,
            'visit_date' => $visitDate,
        ] + ($visitStatus !== null ? ['status' => $visitStatus] : []));

        return Odontogram::factory()->create([
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'tooth_map_payload' => $payload,
        ]);
    }
}

if (! function_exists('lodoOperator')) {
    /**
     * An operator holding the full intake permission set (never Super Admin, so
     * the policies are actually exercised rather than bypassed by Gate::before).
     *
     * @param  list<string>  $permissions
     */
    function lodoOperator(array $permissions = [
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
        'review_legacy_odontogram_imports',
        'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records',
    ]): User
    {
        return userWith($permissions);
    }
}

if (! function_exists('lodoPdfUpload')) {
    /**
     * A structurally valid multi-page PDF upload.
     *
     * Reuses the legacy RME PDF builder because it emits real bytes with a
     * correct xref table — it knows nothing about either archive, so there is
     * nothing to duplicate.
     */
    function lodoPdfUpload(string $name = 'odontogram.pdf', int $pages = 1): File
    {
        // Each call yields DISTINCT bytes.
        //
        // The archive refuses a document whose checksum is already staged or
        // published — the same scanned chart must not land in two patients'
        // clinical histories. Two different paper charts really are different
        // scans, so a fixture that returned identical bytes every time was
        // modelling something that cannot happen. The MediaBox width is nudged by
        // a fraction of a point per call: structurally valid, visually identical,
        // different checksum.
        static $variant = 0;
        $variant++;

        return UploadedFile::fake()->createWithContent(
            $name,
            legacyRmePdfBytes($pages, 595.276 + ($variant / 1000)),
        );
    }
}

if (! function_exists('lodoStageImport')) {
    /**
     * Drive a real upload through the intake service.
     *
     * Uses the service rather than the factory so the fixture exercises the
     * same validation, branch derivation and storage path production does.
     */
    function lodoStageImport(Patient $patient, string $date, User $actor, int $pages = 1): LegacyOdontogramImport
    {
        return app(LegacyOdontogramImportService::class)->createFromUpload(
            $patient,
            $date,
            lodoPdfUpload('odontogram.pdf', $pages),
            $actor,
        );
    }
}
