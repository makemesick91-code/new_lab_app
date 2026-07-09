<?php

use App\Models\User;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive', 'RestoreDrill');

beforeEach(function () {
    seedAccessControl();
});

function writeUiEvidence(array $overrides = []): string
{
    $base = [
        'schema_version' => 1,
        'drill_id' => 'roll-5-1a-ui',
        'environment' => 'staging',
        'source_backup_path' => '/var/backups/deploy/source.sql',
        'source_backup_size_bytes' => 4096,
        'restore_target' => 'daengtisiams_restore_drill_ui',
        'production_overwrite' => false,
        'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'verification' => [
            'db_connectivity' => 'GO', 'migration_consistency' => 'GO', 'app_boot' => 'GO',
            'health_routes' => 'GO', 'sample_readonly_queries' => 'GO', 'pii_redaction_confirmed' => true,
        ],
        'decision' => 'GO',
    ];
    $dir = storage_path('app/readiness/restore-drills');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $path = $dir.'/ui-'.uniqid().'.json';
    file_put_contents($path, json_encode(array_replace($base, $overrides)));
    config()->set('rollout_readiness.paths.restore_drill_evidence', [$path]);

    return $path;
}

afterEach(function () {
    foreach (glob(storage_path('app/readiness/restore-drills/ui-*.json')) ?: [] as $f) {
        @unlink($f);
    }
});

it('shows the restore-drill evidence card and Stage-1 clearance panel', function () {
    writeUiEvidence();

    $this->actingAs(superAdmin())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertOk()
        ->assertSee('Bukti Uji Restore')
        ->assertSee('Kelayakan Stage-1');
});

it('forbids a user without the developer console permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertForbidden();
});

it('redirects guests to login', function () {
    $this->get(route('foundation.rollout.five-branch-readiness'))
        ->assertRedirect(route('login'));
});

it('forbids an operational Doctor role at the authorization layer', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Doctor'))
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertForbidden();
});

it('never renders secrets, env keys, or a KTP/NIK-shaped number on the restore card', function () {
    // Even if a hostile evidence file carried a KTP-shaped value, the parser
    // rejects it as FAIL and the raw value is never rendered.
    writeUiEvidence(['notes' => ['patient 3201234567890123']]);

    $html = $this->actingAs(superAdmin())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain((string) config('app.key'))
        ->and($html)->not->toContain('APP_KEY')
        ->and($html)->not->toContain('DB_PASSWORD')
        ->and(preg_match('/\b\d{15,16}\b/', $html))->toBe(0);
});
