<?php

/**
 * FIX-04b — schema, permissions, wiring and the separation from legacy RME.
 *
 * These are the structural promises the rest of the module rests on: its own
 * tables (never a discriminator bolted onto the legacy RME ones), its own
 * permissions granted to nobody by default, its own private disk, and a
 * published-record table with no soft delete because immutability is the point.
 */

use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramImportPolicy;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramImportRepository;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramNativeReferenceRepository;
use App\Modules\LegacyOdontogram\Repositories\LegacyOdontogramRecordRepository;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
});

it('creates its OWN tables and does not extend the legacy RME ones', function () {
    expect(Schema::hasTable('stg_odontogram_legacy_imports'))->toBeTrue()
        ->and(Schema::hasTable('stg_odontogram_legacy_import_pages'))->toBeTrue()
        ->and(Schema::hasTable('trx_odontogram_legacy_records'))->toBeTrue()
        ->and(Schema::hasTable('trx_odontogram_legacy_record_pages'))->toBeTrue();

    // Rule 2: no discriminator column may be added to the legacy RME tables.
    foreach (['document_type', 'archive_type', 'kind', 'is_odontogram', 'odontogram'] as $column) {
        expect(Schema::hasColumn('trx_rme_legacy_records', $column))->toBeFalse()
            ->and(Schema::hasColumn('stg_rme_legacy_imports', $column))->toBeFalse()
            ->and(Schema::hasColumn('trx_rme_legacy_record_pages', $column))->toBeFalse();
    }
});

it('carries the columns the archive contract needs', function () {
    foreach ([
        'uuid', 'patient_id', 'origin_branch_id', 'source_branch_code',
        'source_medical_record_number', 'selected_odontogram_date',
        'earliest_native_odontogram_date_snapshot', 'source_pdf_sha256',
        'page_count', 'status', 'uploaded_by', 'uploaded_at', 'reviewed_by',
        'reviewed_at', 'failure_code', 'failure_message', 'deleted_at',
    ] as $column) {
        expect(Schema::hasColumn('stg_odontogram_legacy_imports', $column))->toBeTrue();
    }

    foreach ([
        'uuid', 'patient_id', 'branch_id', 'source_import_id', 'odontogram_date',
        'source_pdf_sha256', 'page_count', 'published_by', 'published_at',
        'voided_at', 'voided_by', 'void_reason',
    ] as $column) {
        expect(Schema::hasColumn('trx_odontogram_legacy_records', $column))->toBeTrue();
    }
});

it('gives the PUBLISHED tables no soft delete, because immutability is the point', function () {
    expect(Schema::hasColumn('trx_odontogram_legacy_records', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('trx_odontogram_legacy_record_pages', 'deleted_at'))->toBeFalse();
});

it('registers the five intake permissions plus the clinical read permission', function () {
    foreach ([
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
        'review_legacy_odontogram_imports',
        'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records',
        'view_legacy_odontogram_archive',
    ] as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('grants each archive permission to exactly the roles that were decided, and no others', function () {
    /*
     * FIX-04b shipped these permissions assigned to NO role, which meant a
     * complete capability was reachable only by Super Admin — and the Master
     * Data RME sidebar group excludes Admin Klinik, so the operators who would
     * actually file charts had no way in at all.
     *
     * FEATURE-LEGACY-IMPORT-HUB-1 closed that with an owner decision: mirror the
     * legacy RME archive exactly. This assertion is therefore no longer "nobody
     * holds these" but the stricter "EXACTLY these roles hold each one" — a new
     * grant to any other role fails here, and so does a grant of a duty that was
     * deliberately withheld.
     *
     * SEPARATION OF DUTIES IS THE POINT. Admin Klinik files and never certifies;
     * Supervisor RME certifies and never files; VOID stays with Super Admin,
     * because retracting published clinical evidence is heavier than publishing
     * it.
     *
     * @var array<string, list<string>>
     */
    $expected = [
        'view_legacy_odontogram_imports' => ['Admin Klinik', 'Supervisor RME'],
        'create_legacy_odontogram_imports' => ['Admin Klinik'],
        'review_legacy_odontogram_imports' => ['Supervisor RME'],
        'publish_legacy_odontogram_imports' => ['Supervisor RME'],
        'void_legacy_odontogram_records' => [],
        'view_legacy_odontogram_archive' => [],
    ];

    foreach ($expected as $permission => $allowed) {
        $holders = Role::all()
            // Super Admin holds every permission by seeder design and
            // additionally bypasses via Gate::before; it is never the subject of
            // a least-privilege assertion.
            ->reject(fn (Role $role): bool => $role->name === 'Super Admin')
            ->filter(fn (Role $role): bool => $role->hasPermissionTo($permission))
            ->pluck('name')
            ->values()
            ->all();

        expect($holders)->toEqualCanonicalizing(
            $allowed,
            sprintf('Holders of %s drifted from the decided set.', $permission),
        );
    }
});

it('binds every repository interface and both policies', function () {
    expect(app(LegacyOdontogramImportRepositoryInterface::class))->toBeInstanceOf(LegacyOdontogramImportRepository::class)
        ->and(app(LegacyOdontogramRecordRepositoryInterface::class))->toBeInstanceOf(LegacyOdontogramRecordRepository::class)
        ->and(app(LegacyOdontogramNativeReferenceRepositoryInterface::class))->toBeInstanceOf(LegacyOdontogramNativeReferenceRepository::class)
        ->and(Gate::getPolicyFor(LegacyOdontogramImport::class))->toBeInstanceOf(LegacyOdontogramImportPolicy::class)
        ->and(Gate::getPolicyFor(LegacyOdontogramRecord::class))->toBeInstanceOf(LegacyOdontogramRecordPolicy::class);
});

it('defaults the migration capability to OFF', function () {
    $flags = config('feature_flags.flags');

    expect($flags)->toHaveKey('rme.legacy_odontogram_archive')
        ->and($flags['rme.legacy_odontogram_archive']['default'])->toBeFalse()
        ->and($flags['rme.legacy_odontogram_archive']['risk_level'])->toBe('high')
        ->and(app(LegacyOdontogramFeatureGuard::class)->flagKey())->toBe('rme.legacy_odontogram_archive');
});

it('resolves the flag through FeatureFlagService, because dot keys do not traverse', function () {
    // The literal key contains dots, so config() dot-notation returns null —
    // which would read as "disabled" today and could read as anything tomorrow.
    expect(config('feature_flags.flags.rme.legacy_odontogram_archive'))->toBeNull();

    lodoFlag(true);
    expect(app(LegacyOdontogramFeatureGuard::class)->migrationEnabled())->toBeTrue();

    lodoFlag(false);
    expect(app(LegacyOdontogramFeatureGuard::class)->migrationEnabled())->toBeFalse();
});

it('uses a PRIVATE disk that no framework route can serve', function () {
    $disk = config('filesystems.disks.'.config('legacy_odontogram.storage.disk'));

    expect($disk)->toBeArray()
        ->and($disk['visibility'])->toBe('private')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk['serve'] ?? false)->toBeFalse()
        ->and(config('legacy_odontogram.storage.forbidden_disks'))->toContain('public');
});

it('accepts PDF and nothing else', function () {
    expect(config('legacy_odontogram.upload.allowed_mimes'))->toBe(['application/pdf'])
        ->and(config('legacy_odontogram.upload.pdf_magic'))->toBe('%PDF-');
});

it('models the lifecycle as final Support classes with explicit transitions', function () {
    expect(LegacyOdontogramImportStatus::canTransition(
        LegacyOdontogramImportStatus::READY_FOR_REVIEW,
        LegacyOdontogramImportStatus::PUBLISHED,
    ))->toBeFalse()
        ->and(LegacyOdontogramImportStatus::canTransition(
            LegacyOdontogramImportStatus::REVIEWED,
            LegacyOdontogramImportStatus::PUBLISHED,
        ))->toBeTrue()
        ->and(LegacyOdontogramImportStatus::nextStatuses(LegacyOdontogramImportStatus::PUBLISHED))->toBe([])
        ->and(LegacyOdontogramRecordStatus::TRANSITIONS[LegacyOdontogramRecordStatus::PUBLISHED])
        ->toBe([LegacyOdontogramRecordStatus::VOID])
        ->and(LegacyOdontogramRecordStatus::TRANSITIONS[LegacyOdontogramRecordStatus::VOID])->toBe([]);

    expect((new ReflectionClass(LegacyOdontogramImportStatus::class))->isFinal())->toBeTrue()
        ->and((new ReflectionClass(LegacyOdontogramRecordStatus::class))->isFinal())->toBeTrue();
});

it('registers intake under settings and the viewer under rme', function () {
    expect(route('settings.rme.legacy-odontograms.index'))->toContain('/settings/rme/legacy-odontograms')
        ->and(route('settings.rme.legacy-odontograms.create'))->toContain('/settings/rme/legacy-odontograms/create')
        ->and(route('rme.legacy-odontograms.show', 1))->toContain('/rme/legacy-odontograms/1');
});
