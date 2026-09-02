<?php

declare(strict_types=1);

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmePatientResolutionAuditService;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RmeBranchSeeder;
use Illuminate\Database\QueryException;

/**
 * REVISION-SUNU-BRANCH-CODE-SUN4-TO-SPN4-1
 *
 * Cabang Sunu's canonical branch code is SPN4. SUN4 is the DEPRECATED
 * historical code: still recognised, never emitted.
 *
 * These tests pin the contract from both directions — that the canonical code
 * works, and that the historical one keeps resolving to the SAME branch so the
 * card in a patient's wallet and the number printed on an archived document do
 * not stop working the day the code was revised.
 *
 * They exist for a reason that was LIVE in production, not theoretical. The
 * branch row had been renamed to SPN4 by hand while the application still said
 * SUN4, so one patient's Nomor RM named a branch code that existed nowhere and
 * the ACTIVE rollout wave listed a spelling the branch master no longer used.
 */
function sunuBranch(): Branch
{
    return legacyRmeBranch(BranchCodeAlias::SUNU_CANONICAL, 'Cabang Sunu');
}

/*
|--------------------------------------------------------------------------
| The alias policy itself
|--------------------------------------------------------------------------
*/

it('canonicalizes the deprecated Sunu code and leaves the canonical one alone', function () {
    expect(BranchCodeAlias::canonicalize('SUN4'))->toBe('SPN4')
        ->and(BranchCodeAlias::canonicalize('SPN4'))->toBe('SPN4')
        ->and(BranchCodeAlias::canonicalize('LDK2'))->toBe('LDK2')
        ->and(BranchCodeAlias::isHistoricalAlias('SUN4'))->toBeTrue()
        ->and(BranchCodeAlias::isHistoricalAlias('SPN4'))->toBeFalse();
});

it('normalizes case and surrounding whitespace before matching', function () {
    expect(BranchCodeAlias::canonicalize('  sun4 '))->toBe('SPN4')
        ->and(BranchCodeAlias::canonicalize('Spn4'))->toBe('SPN4');
});

it('is idempotent — canonicalizing twice is canonicalizing once', function () {
    $once = BranchCodeAlias::canonicalize('SUN4');

    expect(BranchCodeAlias::canonicalize($once))->toBe($once)->toBe('SPN4');
});

it('never re-emits the deprecated Sunu code for the canonical one', function () {
    // The map is one-way: no canonical code may appear as a key, or
    // canonicalize() would not converge and a NEW record could be created
    // carrying a deprecated spelling.
    foreach (BranchCodeAlias::all() as $deprecated => $canonical) {
        expect(BranchCodeAlias::all())->not->toHaveKey($canonical);
    }

    expect(BranchCodeAlias::all())->toHaveKey('SUN4')
        ->and(BranchCodeAlias::all()['SUN4'])->toBe('SPN4')
        ->and(BranchCodeAlias::all())->not->toHaveKey('SPN4');
});

it('FAILS CLOSED — an unknown or partial code is never mapped onto Sunu', function () {
    // Neighbours of the real codes. None of these is Cabang Sunu.
    foreach (['SUN5', 'SPN5', 'SNU4', 'SUN', 'SPN', 'SUN44', 'SUN4X', 'XSUN4', 'ZZZ9'] as $unknown) {
        expect(BranchCodeAlias::canonicalize($unknown))
            ->toBe(strtoupper($unknown))
            ->not->toBe('SPN4');
    }

    expect(BranchCodeAlias::canonicalize(''))->toBeNull()
        ->and(BranchCodeAlias::canonicalize(null))->toBeNull();
});

it('reports every deprecated spelling that names Cabang Sunu', function () {
    expect(BranchCodeAlias::historicalAliasesFor('SPN4'))->toBe(['SUN4'])
        ->and(BranchCodeAlias::equivalentCodes('SPN4'))->toBe(['SPN4', 'SUN4'])
        // Looking up BY the old spelling must widen to the same pair.
        ->and(BranchCodeAlias::equivalentCodes('SUN4'))->toBe(['SPN4', 'SUN4'])
        // Telkomas is a separate entry and must not be dragged in.
        ->and(BranchCodeAlias::equivalentCodes('LDK2'))->toBe(['LDK2']);
});

it('keeps the Telkomas revision intact alongside the Sunu one', function () {
    // Adding a branch to the registry must not disturb the one already there.
    expect(BranchCodeAlias::canonicalize('TKM1'))->toBe('TLK1')
        ->and(BranchCodeAlias::canonicalize('SUN4'))->toBe('SPN4')
        ->and(BranchCodeAlias::equivalentCodes('TLK1'))->toBe(['TLK1', 'TKM1']);
});

/*
|--------------------------------------------------------------------------
| Nomor RM generation and transformation
|--------------------------------------------------------------------------
*/

it('issues NEW Sunu medical record numbers under the canonical code', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    $issued = $numbers->composeForRegistration(BranchCodeAlias::SUNU_CANONICAL, null, '564');

    expect($issued)->toStartWith('DG-SPN4-')
        ->and($issued)->not->toContain('SUN4');
});

it('reports the CANONICAL branch for a medical record number issued under the deprecated code', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->branchCodeFrom('DG-SUN4-2026-564'))->toBe('SPN4')
        // The literal spelling is still readable — that is the historical fact.
        ->and($numbers->literalBranchCodeFrom('DG-SUN4-2026-564'))->toBe('SUN4');
});

it('rewrites ONLY the branch segment — year and manual sequence survive verbatim', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->canonicalizeBranchCode('DG-SUN4-2026-564'))->toBe('DG-SPN4-2026-564')
        ->and($numbers->canonicalizeBranchCode('DG-SUN4-2019-0007'))->toBe('DG-SPN4-2019-0007')
        // A sequence may itself contain a hyphen; it is carried through whole.
        ->and($numbers->canonicalizeBranchCode('DG-SUN4-2024-12-A'))->toBe('DG-SPN4-2024-12-A')
        // Already canonical is returned unchanged, so the call is idempotent.
        ->and($numbers->canonicalizeBranchCode('DG-SPN4-2026-564'))->toBe('DG-SPN4-2026-564');
});

it('refuses to transform anything that is not a canonical Nomor RM', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    foreach (['SUN4', 'DG-SUN4', 'DG-SUN4-2026', 'XXSUN4YY', 'RM SUN4 2026', ''] as $notAnRm) {
        expect($numbers->canonicalizeBranchCode($notAnRm))->toBeNull();
    }
});

it('matches the branch code as a WHOLE token, never as a substring', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    // The branch segment is parsed, not searched for. A code that merely
    // CONTAINS SUN4 is a different branch and is left completely alone.
    expect($numbers->canonicalizeBranchCode('DG-SUN40-2026-1'))->toBe('DG-SUN40-2026-1')
        ->and($numbers->canonicalizeBranchCode('DG-XSUN4-2026-1'))->toBe('DG-XSUN4-2026-1')
        // `XXSUN4YY` is a structurally VALID branch token that merely contains
        // the deprecated code. The parser accepts the shape, the alias map does
        // not recognise the code, so the value comes back untouched and the
        // caller's branch lookup then finds nothing. That is the fail-closed
        // path, and it is the one a blind str_replace would have corrupted.
        ->and($numbers->canonicalizeBranchCode('DG-XXSUN4YY-2026-1'))->toBe('DG-XXSUN4YY-2026-1');
});

it('never rewrites a SUN4 appearing inside the manual sequence', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    // This is the exact corruption a blind str_replace would cause: the
    // sequence is the clinic's own paper numbering and is not a branch code.
    expect($numbers->canonicalizeBranchCode('DG-LDK2-2026-SUN4'))->toBe('DG-LDK2-2026-SUN4')
        ->and($numbers->canonicalizeBranchCode('DG-SUN4-2026-SUN4'))->toBe('DG-SPN4-2026-SUN4');
});

/*
|--------------------------------------------------------------------------
| RM-derived branch resolution (Legacy RME AND Legacy Odontogram)
|--------------------------------------------------------------------------
*/

it('resolves a deprecated-code Nomor RM to the SAME Sunu branch identity', function () {
    $branch = sunuBranch();

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);

    expect($resolution->resolved)->toBeTrue()
        ->and($resolution->branchId)->toBe((int) $branch->id)
        // The RESOLVED code is canonical, so allowlists compare one spelling.
        ->and($resolution->branchCode)->toBe('SPN4');
});

it('resolves a canonical Nomor RM to the same branch as the deprecated one', function () {
    $branch = sunuBranch();

    $historical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-1111',
        'date_of_birth' => '1990-01-01',
    ]);
    $canonical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SPN4-2026-2222',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolver = app(LegacyRmeBranchResolver::class);

    expect($resolver->resolveForPatient($historical)->branchId)
        ->toBe($resolver->resolveForPatient($canonical)->branchId)
        ->toBe((int) $branch->id);
});

it('FAILS CLOSED for an unknown branch code rather than falling back to Sunu', function () {
    sunuBranch();

    $patient = Patient::factory()->create([
        'medical_record_number' => 'DG-SUN5-2026-0001',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);

    expect($resolution->resolved)->toBeFalse()
        ->and($resolution->code)->toBe(LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND);
});

/*
|--------------------------------------------------------------------------
| Historical Nomor RM search compatibility (the patient's old card)
|--------------------------------------------------------------------------
*/

it('finds a migrated Sunu patient from the OLD number on every RM lookup surface', function () {
    $branch = sunuBranch();

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        // Already migrated to the canonical spelling.
        'medical_record_number' => 'DG-SPN4-2026-564',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
    ]);

    $old = 'DG-SUN4-2026-564';

    // 1. Legacy odontogram intake — the operator is holding the paper chart.
    $odontogram = app(LegacyOdontogramPatientRepositoryInterface::class)
        ->searchByMedicalRecordNumber(null, $old, 10);

    // 2. Legacy RME identity resolution — this one BINDS a document to a patient.
    $identity = app(LegacyRmePatientResolutionAuditService::class)
        ->resolveIdentity($old);

    // 3. The paginated patient directory, and 4. the selectable combobox.
    $repository = app(PatientRepositoryInterface::class);
    $directory = $repository->paginate(['search' => $old], 15);
    $combobox = $repository->searchSelectable([(int) $branch->id], $old, 15);

    // 5. New Visit's global, cross-branch patient lookup.
    $crossBranch = app(CrossBranchPatientLookupService::class)
        ->lookupByMedicalRecordNumberAcrossBranches($old);

    expect($odontogram->pluck('id')->all())->toBe([$patient->id])
        ->and($identity['bindable'])->toBeTrue()
        ->and($identity['matches'])->toHaveCount(1)
        ->and((int) $identity['matches'][0]['patient_id'])->toBe($patient->id)
        ->and($directory->pluck('id')->all())->toBe([$patient->id])
        ->and($combobox->pluck('id')->all())->toBe([$patient->id])
        ->and($crossBranch)->not->toBeNull();
});

it('does not widen an unrelated search term into someone else', function () {
    $branch = sunuBranch();

    Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SPN4-2026-564',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
    ]);

    $repository = app(PatientRepositoryInterface::class);

    // A neighbouring branch code, and a different sequence, must find nobody.
    expect($repository->paginate(['search' => 'DG-SUN5-2026-564'], 15)->pluck('id')->all())->toBe([])
        ->and($repository->paginate(['search' => 'DG-SUN4-2026-565'], 15)->pluck('id')->all())->toBe([]);
});

it('never creates a second patient row for the historical Sunu spelling', function () {
    $branch = sunuBranch();

    Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SPN4-2026-564',
        'date_of_birth' => '1990-01-01',
    ]);

    app(CrossBranchPatientLookupService::class)
        ->lookupByMedicalRecordNumberAcrossBranches('DG-SUN4-2026-564');

    expect(Patient::withTrashed()->where('medical_record_number', 'like', 'DG-S%4-2026-564')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Master data registry
|--------------------------------------------------------------------------
*/

it('seeds Cabang Sunu under the canonical code', function () {
    test()->seed(BranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    $sunu = Branch::query()->where('code', 'SPN4')->get();

    expect($sunu)->toHaveCount(1)
        ->and($sunu->first()->name)->toBe('Cabang Sunu')
        ->and($sunu->first()->is_active)->toBeTrue()
        ->and($sunu->first()->is_rme_enabled)->toBeTrue()
        ->and(Branch::withTrashed()->where('code', 'SUN4')->count())->toBe(0)
        ->and(RmeBranchSeeder::CANONICAL_RME_BRANCHES)->not->toHaveKey('SUN4')
        ->and(RmeBranchSeeder::CANONICAL_RME_BRANCHES)->toHaveKey('SPN4');
});

it('NEVER duplicates Cabang Sunu when the branch still holds its deprecated code', function () {
    test()->seed(BranchSeeder::class);

    // A deployment that has not been migrated yet. Production was found in the
    // MIRROR of this state — already renamed — but a seeder that duplicates in
    // either direction splits one clinic across two branch ids, and branch id
    // is the isolation boundary.
    $existing = Branch::query()->create([
        'code' => 'SUN4',
        'name' => 'Cabang Sunu',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => false,
    ]);

    test()->seed(RmeBranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    $sunuRows = Branch::withTrashed()->whereIn('code', ['SUN4', 'SPN4'])->get();

    expect($sunuRows)->toHaveCount(1)
        ->and((int) $sunuRows->first()->id)->toBe((int) $existing->id);
});

it('NEVER duplicates a SOFT-DELETED Cabang Sunu that still holds the deprecated code', function () {
    test()->seed(BranchSeeder::class);

    // The nastiest shape of all, and the one a mutation proved was uncovered:
    // the branch is BOTH soft-deleted AND still spelled with the deprecated
    // code. Matching only live rows, or only the canonical code, misses it and
    // the seeder then CREATES a second Cabang Sunu — splitting one clinic's
    // patients across two branch ids, which is the isolation boundary.
    $existing = Branch::query()->create([
        'code' => 'SUN4',
        'name' => 'Cabang Sunu',
        'is_active' => false,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);
    $existing->delete();

    test()->seed(RmeBranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    $sunuRows = Branch::withTrashed()->whereIn('code', ['SUN4', 'SPN4'])->get();

    expect($sunuRows)->toHaveCount(1)
        ->and((int) $sunuRows->first()->id)->toBe((int) $existing->id)
        // Restored and re-enabled, so it is selectable again...
        ->and($sunuRows->first()->deleted_at)->toBeNull()
        ->and($sunuRows->first()->is_active)->toBeTrue()
        ->and($sunuRows->first()->is_rme_enabled)->toBeTrue()
        // ...but NOT renamed here. Converting a deprecated code to the canonical
        // one is the migration's job, because only it checks for a collision.
        ->and($sunuRows->first()->code)->toBe('SUN4');
});

it('proves the database itself forbids two branches sharing one code', function () {
    // This is why the migration's "more than one branch holds the deprecated
    // code" guard can never fire: `mst_branches.code` carries a PLAIN unique
    // index, which in Laravel spans soft-deleted rows too. The guard is kept as
    // defence in depth against a future partial index, but the ambiguity it
    // describes is unreachable while this constraint holds — so the test states
    // the constraint rather than pretending to exercise the branch.
    Branch::query()->create([
        'code' => 'SUN4', 'name' => 'Cabang Sunu', 'is_active' => true, 'is_rme_enabled' => true,
    ])->delete();

    expect(fn () => Branch::query()->create([
        'code' => 'SUN4', 'name' => 'Cabang Sunu Duplikat', 'is_active' => true, 'is_rme_enabled' => true,
    ]))->toThrow(QueryException::class);
});

it('restores and re-enables Cabang Sunu under the canonical code', function () {
    test()->seed(BranchSeeder::class);

    // The restore/re-enable branch of the seeder is keyed on the canonical
    // constant. A hard-coded deprecated literal there would make this block
    // dead code the moment the code was revised, and a soft-deleted Sunu would
    // stay unselectable forever.
    $trashed = Branch::query()->create([
        'code' => 'SPN4',
        'name' => 'Cabang Sunu',
        'is_active' => false,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => false,
    ]);
    $trashed->delete();

    test()->seed(RmeBranchSeeder::class);

    $restored = Branch::query()->where('code', 'SPN4')->first();

    expect($restored)->not->toBeNull()
        ->and((int) $restored->id)->toBe((int) $trashed->id)
        ->and($restored->is_active)->toBeTrue()
        ->and($restored->is_rme_enabled)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Rollout admission — a stale environment must not lock the branch out
|--------------------------------------------------------------------------
*/

it('canonicalizes rollout allowlist tokens declared with the deprecated Sunu code', function () {
    // The REAL config file is re-evaluated against a deployment whose
    // environment still names the deprecated code — the production state this
    // revision found, where the ACTIVE wave listed SUN4 and the branch master
    // already answered SPN4.
    $build = static function (string $admitted, string $approved, string $pilot): array {
        putenv("LEGACY_RME_ADMITTED_BRANCH_CODES={$admitted}");
        putenv("LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES={$approved}");
        putenv("LEGACY_RME_PILOT_BRANCH_CODE={$pilot}");

        try {
            return require base_path('config/legacy_rme_rollout.php');
        } finally {
            putenv('LEGACY_RME_ADMITTED_BRANCH_CODES');
            putenv('LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES');
            putenv('LEGACY_RME_PILOT_BRANCH_CODE');
        }
    };

    $stale = $build('TLK1,LDK2,ATG3,SUN4', 'TLK1,LDK2,ATG3,SUN4', 'SUN4');

    expect($stale['admission']['admitted_branch_codes'])->toBe(['TLK1', 'LDK2', 'ATG3', 'SPN4'])
        ->and($stale['admission']['approved_branch_codes'])->toBe(['TLK1', 'LDK2', 'ATG3', 'SPN4'])
        ->and($stale['pilot_scope']['branch_code'])->toBe('SPN4')
        // Admitted must stay covered by the approval — canonicalizing BOTH sides
        // is what keeps that invariant true.
        ->and(array_diff(
            $stale['admission']['admitted_branch_codes'],
            $stale['admission']['approved_branch_codes'],
        ))->toBe([]);

    // Declaring both spellings collapses to one token rather than admitting the
    // same branch twice.
    $mixed = $build('SPN4,SUN4', 'SPN4,SUN4', 'SPN4');

    expect($mixed['admission']['admitted_branch_codes'])->toBe(['SPN4']);

    // An unknown token is carried through untouched and still matches nothing.
    $unknown = $build('SUN5', 'SUN5', '');

    expect($unknown['admission']['admitted_branch_codes'])->toBe(['SUN5'])
        ->and($unknown['pilot_scope']['branch_code'])->toBe('');
});

it('ADMITS a Sunu patient whose Nomor RM still carries the deprecated code', function () {
    $branch = sunuBranch();
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['SPN4']);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SUN4-2026-564',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);
    $decision = app(LegacyRmeBranchAdmissionService::class)->decide($resolution);

    expect($resolution->resolved)->toBeTrue()
        ->and($decision->admitted)->toBeTrue()
        ->and($decision->branchCode)->toBe('SPN4');
});

it('ADMITS a Sunu patient when the ALLOWLIST is the stale spelling', function () {
    // The production shape, exactly: branch master says SPN4, the approved wave
    // still says SUN4. Canonicalizing BOTH sides is what stops an approved
    // branch being locked out of its own wave.
    $branch = sunuBranch();
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['SUN4']);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-SPN4-2026-564',
        'date_of_birth' => '1990-01-01',
    ]);

    $decision = app(LegacyRmeBranchAdmissionService::class)
        ->decide(app(LegacyRmeBranchResolver::class)->resolveForPatient($patient));

    expect($decision->admitted)->toBeTrue()
        ->and($decision->branchCode)->toBe('SPN4');
});

it('canonicalizes inside decide(), not merely upper-cases, on the retry path', function () {
    // decideForBranchCode() is the RETRY path for an already-persisted import,
    // whose stored origin_branch_code may still be the historical spelling. It
    // only trims and upper-cases its argument — canonicalization happens inside
    // decide(). That distinction is the whole of ENT-style defence in depth: an
    // upper-case-only normalizer would leave 'SUN4' as 'SUN4', compare it
    // against a canonicalized allowlist, miss, and refuse an approved branch.
    sunuBranch();
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['SPN4']);

    $service = app(LegacyRmeBranchAdmissionService::class);

    expect($service->decideForBranchCode('SUN4')->admitted)->toBeTrue()
        ->and($service->decideForBranchCode('SUN4')->branchCode)->toBe('SPN4')
        ->and($service->decideForBranchCode('  sun4  ')->admitted)->toBeTrue()
        ->and($service->decideForBranchCode('SPN4')->admitted)->toBeTrue()
        // An unknown neighbour still fails closed on the same path.
        ->and($service->decideForBranchCode('SUN5')->admitted)->toBeFalse();
});

it('still DENIES a branch that is not admitted, deprecated code or not', function () {
    sunuBranch();
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['LDK2']);

    $patient = Patient::factory()->create([
        'branch_id' => Branch::query()->where('code', 'SPN4')->value('id'),
        'medical_record_number' => 'DG-SUN4-2026-564',
        'date_of_birth' => '1990-01-01',
    ]);

    $decision = app(LegacyRmeBranchAdmissionService::class)
        ->decide(app(LegacyRmeBranchResolver::class)->resolveForPatient($patient));

    expect($decision->admitted)->toBeFalse();
});
