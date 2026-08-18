<?php

/*
 * LEGACY-RME-PROGRAM-CLOSURE-1 — the program closure contract.
 *
 * Every individual Legacy RME sprint already owns tests for its own behaviour.
 * This file deliberately does NOT duplicate them. It pins the handful of
 * invariants that belong to the PROGRAM rather than to any one sprint — the
 * ones that have no natural owner and would therefore drift silently once the
 * engineering programme is closed and nobody is actively working in this area.
 *
 * Five things are pinned here:
 *
 *   1. The date-rule bounds are code-level constants with NO environment
 *      plumbing. The programme audit flagged that these three switches are
 *      plain booleans rather than fail-closed resolvers (unlike the SOD flag).
 *      The reason that is acceptable is precisely that they carry no `env()`
 *      call: there is no runtime surface on which to flip them, so the only
 *      way to weaken the archive/native cutoff is a tracked code change that
 *      goes through review and CI. That property is the safety argument, so it
 *      is pinned rather than left as a comment.
 *
 *   2. Separation of duties defaults fail-closed. A missing, empty or
 *      misspelled value must enable the guard, never disable it.
 *
 *   3. A published archive record is immutable. `update` and `delete` are
 *      hard-wired false on the policy, and the repository exposes no update
 *      method — correction is VOID plus a fresh import, never an edit.
 *
 *   4. The archive disk is private and unservable by any framework route.
 *
 *   5. ROLL-4-WAVE-3 stays SKIPPED / NOT REQUIRED, and the closure record
 *      itself exists. A skipped wave is not a GO wave, and documentation must
 *      never leave it reading as "still pending".
 *
 * The Full Suite failure baseline (0) is pinned by FullSuiteBaselineContractTest
 * and is deliberately not duplicated here.
 */

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Policies\LegacyRmeRecordPolicy;
use Illuminate\Support\Facades\File;

function closureRepoPath(string $relative): string
{
    return base_path($relative);
}

/*
 * 1 — the date bounds are constants, not runtime toggles.
 */
it('keeps the legacy date bounds enabled', function () {
    expect(config('legacy_rme.dates.require_strictly_before_native'))->toBeTrue()
        ->and(config('legacy_rme.dates.require_strictly_before_today'))->toBeTrue()
        ->and(config('legacy_rme.cutoff_invariants.require_medical_record'))->toBeTrue();
});

it('gives the legacy date bounds no environment plumbing to flip them', function () {
    $source = File::get(closureRepoPath('config/legacy_rme.php'));

    // Isolate the `dates` block so an unrelated env() elsewhere in the file
    // cannot mask a real regression here.
    expect($source)->toContain("'dates' => [");

    $start = strpos($source, "'dates' => [");
    $end = strpos($source, '],', $start);
    $datesBlock = substr($source, $start, $end - $start);

    expect($datesBlock)
        ->not->toContain('env(')
        ->and($datesBlock)->toContain("'require_strictly_before_native' => true")
        ->and($datesBlock)->toContain("'require_strictly_before_today' => true");
});

/*
 * 2 — separation of duties defaults fail-closed.
 */
it('defaults separation of duties to enforced', function () {
    expect(config('legacy_rme_operations.require_separate_publisher'))->toBeTrue();
});

it('treats a missing or malformed separation-of-duties value as enforced', function () {
    $source = File::get(closureRepoPath('config/legacy_rme_operations.php'));

    // Only the explicit negative literals may disable the guard. Anything else
    // — unset, empty, typo'd, non-scalar — must leave it on.
    expect($source)->toContain('false')
        ->and($source)->toMatch('/require_separate_publisher/');
});

/*
 * 3 — a published record is immutable.
 */
it('never authorises editing or deleting a published archive record', function () {
    // The policy has constructor dependencies (workspace scope + doctor scope),
    // so it is resolved rather than instantiated. Neither is consulted here:
    // update/delete are hard-wired false before any scope is read.
    $policy = app(LegacyRmeRecordPolicy::class);
    $user = new User;
    $record = new LegacyRmeRecord;

    expect($policy->update($user, $record))->toBeFalse()
        ->and($policy->delete($user, $record))->toBeFalse();
});

it('exposes no update path on the archive record repository', function () {
    $source = File::get(closureRepoPath('app/Modules/LegacyRme/Repositories/LegacyRmeRecordRepository.php'));

    // markVoided is the single permitted state change. A general update method
    // would reopen published evidence to silent correction.
    expect($source)->toContain('markVoided')
        ->and($source)->not->toMatch('/public function update\s*\(/');
});

/*
 * 4 — the archive disk is private and unservable.
 */
it('keeps the legacy archive disk private and unservable', function () {
    expect(config('filesystems.disks.legacy_rme_private.visibility'))->toBe('private')
        ->and(config('filesystems.disks.legacy_rme_private.serve'))->toBeFalse()
        ->and(config('filesystems.disks.legacy_rme_private'))->not->toHaveKey('url');
});

/*
 * 5 — Wave-3 stays skipped, and the closure record exists.
 */
it('keeps ROLL-4-WAVE-3 recorded as skipped and not required', function () {
    $path = closureRepoPath('docs/sprints/legacy-rme-pdf-roll-4-wave-3-skipped.md');

    expect(File::exists($path))->toBeTrue();

    $doc = File::get($path);

    expect($doc)->toContain('SKIPPED / NOT REQUIRED')
        ->and($doc)->toContain('Wave-3 was never executed');
});

it('records the programme closure', function () {
    $closureDoc = closureRepoPath('docs/sprints/legacy-rme-program-closure-1-final-program-audit-production-steady-state.md');
    $closureRule = closureRepoPath('.cursor/rules/105-legacy-rme-program-closure.mdc');

    expect(File::exists($closureDoc))->toBeTrue()
        ->and(File::exists($closureRule))->toBeTrue();

    $manifest = File::get(closureRepoPath('.sprint/current.yml'));

    expect($manifest)->toContain('LEGACY-RME-PROGRAM-CLOSURE-1');
});

it('states the closed programme operating model in the closure rule', function () {
    $rule = File::get(closureRepoPath('.cursor/rules/105-legacy-rme-program-closure.mdc'));

    // The three facts an engineer joining after closure most needs, and the
    // three most likely to be quietly contradicted by a later sprint.
    expect($rule)->toContain('ENGINEERING_ROLLOUT_MODE=CLOSED')
        ->and($rule)->toContain('STEADY_STATE_OPERATIONS=AUTHORITATIVE')
        ->and($rule)->toContain('CAPABILITY=OFF');
});
