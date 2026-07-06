<?php

use App\Models\User;
use App\Support\DeveloperConsole\DeveloperConsoleService;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('DeveloperConsole', 'EnterpriseFoundation');

beforeEach(function () {
    seedAccessControl();
});

// ---------------------------------------------------------------------------
// Access & authorization
// ---------------------------------------------------------------------------

it('lets the Super Admin open the developer console', function () {
    $this->actingAs(superAdmin())
        ->get(route('developer-console.index'))
        ->assertOk()
        ->assertSee('Developer Assistance Console');
});

it('lets a user holding only view_developer_console open the console', function () {
    $this->actingAs(userWith(['view_developer_console']))
        ->get(route('developer-console.index'))
        ->assertOk();
});

it('forbids a user without the console permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('developer-console.index'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('developer-console.index'))
        ->assertRedirect(route('login'));
});

it('does not grant the console permission to any pilot role except Super Admin', function () {
    foreach (['Owner', 'Kasir', 'Perawat', 'Admin Lab'] as $role) {
        expect(userInRole($role)->can('view_developer_console'))->toBeFalse("{$role} must not access the developer console");
    }
});

// ---------------------------------------------------------------------------
// Audit trail
// ---------------------------------------------------------------------------

it('writes an immutable audit row for every console access', function () {
    $admin = superAdmin();

    $this->actingAs($admin)->get(route('developer-console.index'))->assertOk();

    $this->assertDatabaseHas('sys_audit_logs', [
        'entity_type' => 'developer_console',
        'action' => 'VIEW_DEVELOPER_CONSOLE',
        'performed_by' => $admin->id,
    ]);
});

// ---------------------------------------------------------------------------
// Read-only service sections
// ---------------------------------------------------------------------------

it('returns every configured console section without mutating state', function () {
    $overview = app(DeveloperConsoleService::class)->overview();

    expect(array_keys($overview))->toBe([
        'application_log', 'failed_jobs', 'audit_events', 'slow_queries',
        'deploy_evidence', 'storage_health', 'runtime_health', 'disk_backup',
    ]);

    foreach ($overview as $name => $section) {
        expect($section['status'])->toBeIn(['ok', 'unavailable'], "section {$name}");
    }

    expect($overview['runtime_health']['db_ping_ms'])->toBeFloat()
        ->and($overview['storage_health']['all_writable'])->toBeTrue()
        ->and($overview['failed_jobs']['table_exists'])->toBeTrue();
});

it('masks failed job exception excerpts before rendering', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => "Exception: KTP 3201234567890123 leaked with password=super-secret-value\nstack trace line",
        'failed_at' => now(),
    ]);

    $section = app(DeveloperConsoleService::class)->overview()['failed_jobs'];

    expect($section['total'])->toBe(1);

    $excerpt = $section['recent'][0]['exception_excerpt'];

    expect($excerpt)->not->toContain('3201234567890123')
        ->and($excerpt)->not->toContain('super-secret-value')
        ->and($excerpt)->toContain('[MASKED]');
});

// ---------------------------------------------------------------------------
// Masker unit behaviour (ENT7-DC004/DC005)
// ---------------------------------------------------------------------------

it('masks KTP-shaped digit runs credentials tokens and emails', function () {
    $masker = app(SensitiveValueMasker::class);

    $masked = $masker->mask(
        'nik=3201234567890123 password: hunter7 api_key="abc123" Authorization: Bearer eyJhbGciOi budi@example.com'
    );

    expect($masked)->not->toContain('3201234567890123')
        ->and($masked)->not->toContain('hunter7')
        ->and($masked)->not->toContain('abc123')
        ->and($masked)->not->toContain('eyJhbGciOi')
        ->and($masked)->not->toContain('budi@')
        ->and($masked)->toContain('[MASKED]');
});

it('still collapses digit runs even when masking is misconfigured off', function () {
    config(['developer_console.masking.enabled' => false]);

    $masked = app(SensitiveValueMasker::class)->mask('KTP 3201234567890123');

    expect($masked)->not->toContain('3201234567890123');
});
