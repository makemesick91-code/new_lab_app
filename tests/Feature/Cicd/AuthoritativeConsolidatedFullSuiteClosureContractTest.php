<?php

/*
 * AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-AND-CLOSURE-1 — the closure contract.
 *
 * The stabilization programme ends with one explicitly-authorised consolidated
 * Full Suite on a frozen candidate. The facts that make that closure meaningful
 * are exactly the facts a later sprint is most likely to contradict quietly:
 * what "closed" means, that a red suite is never simply rerun, that the tested
 * tree is the deployed tree, and — the one most likely to be overstated — the
 * list of things closure does NOT claim.
 *
 * This file lives in tests/Feature/Cicd/ deliberately. The Critical gate's
 * filter is a substring match against the fully-qualified test name, which
 * includes the namespace, so `Cicd` selects everything in this directory.
 * Verified with `pest --list-tests`, not by reading the filter — per R-20, "is
 * my class name in the filter" is the wrong question.
 */

use Illuminate\Support\Facades\File;

function closureContractDoc(): string
{
    return (string) File::get(
        base_path('docs/sprints/authoritative-consolidated-full-suite-and-closure-1.md')
    );
}

function closureContractRule(): string
{
    return (string) File::get(
        base_path('.cursor/rules/122-authoritative-consolidated-full-suite-closure.mdc')
    );
}

it('ships the closure record and its durable rule mirror', function () {
    expect(File::exists(base_path('docs/sprints/authoritative-consolidated-full-suite-and-closure-1.md')))->toBeTrue()
        ->and(File::exists(base_path('.cursor/rules/122-authoritative-consolidated-full-suite-closure.mdc')))->toBeTrue();
});

it('states every closure rule in the durable mirror', function () {
    $rule = closureContractRule();

    // toContain is variadic — each argument is a NEEDLE, never a failure
    // message. All six ids are asserted in one call on purpose.
    expect($rule)->toContain(
        'CLOSE-R01',
        'CLOSE-R02',
        'CLOSE-R03',
        'CLOSE-R04',
        'CLOSE-R05',
        'CLOSE-R06',
    );
});

it('defines closure as tested-tree equals merged tree equals deployed tree', function () {
    $rule = closureContractRule();

    expect($rule)->toContain('FULL_SUITE_PASSED_CANDIDATE_TREE == RUNTIME_MERGE_TREE == DEPLOYED_TREE');
});

it('forbids rerunning a failed authoritative Full Suite without new authorisation', function () {
    $rule = closureContractRule();

    expect($rule)->toContain('is **NOT** automatically rerun')
        ->and($rule)->toContain('NEW explicit user authorisation');
});

it('records what closure deliberately does not claim', function () {
    $rule = closureContractRule();

    // The four items whose audited classification must survive closure. If a
    // later sprint wants to claim one of these is finished, it has to change
    // this rule explicitly — it cannot drift into a summary sentence.
    expect($rule)->toContain(
        'WhatsApp / Meta prescription delivery',
        'SATUSEHAT external submission',
        'object-storage production cutover',
        'dangling storage references',
    );
});

it('keeps the temporary Full-Suite policy retirement outside the closure candidate', function () {
    $rule = closureContractRule();

    // CLOSE-R05 is mechanical, not stylistic: RETIRED re-authorises the
    // post-merge push-to-base path, so retiring it inside the closure PR would
    // fire a second, unauthorised Full Suite the moment the PR merged.
    expect($rule)->toContain('SECOND, unauthorised Full');
});

it('keeps the Full Suite gated on an explicit fail-closed authorisation', function () {
    $workflow = (string) File::get(base_path('.github/workflows/foundation-evidence-gates.yml'));

    // The durable safety property, independent of whether the temporary policy
    // is ACTIVE or RETIRED: the Full Suite job may only run when the resolver
    // has affirmatively authorised it, and the resolver fails closed.
    expect($workflow)->toContain("needs.classify.outputs.full_suite_authorized == 'true'");

    $resolver = (string) File::get(base_path('scripts/ci/resolve-full-suite-policy.sh'));

    expect($resolver)->toContain('POLICY_STATE_UNRESOLVED_FAIL_CLOSED');
});

it('records the closure in the project memory file', function () {
    $claude = (string) File::get(base_path('CLAUDE.md'));

    expect($claude)->toContain('AUTHORITATIVE-CONSOLIDATED-FULL-SUITE-AND-CLOSURE-1');
});

it('names the inherited programme closure in the sprint manifest', function () {
    $manifest = (string) File::get(base_path('.sprint/current.yml'));

    // Mirrors the LegacyRme closure contract: every sprint after closure has to
    // name the closure it inherits.
    expect($manifest)->toContain('LEGACY-RME-PROGRAM-CLOSURE-1');
});

it('carries the frozen residual ledger forward without inventing a real defect', function () {
    $doc = closureContractDoc();

    expect($doc)->toContain('REAL_DEFECT=0')
        ->and($doc)->toContain('BLOCKING_RESIDUALS=0');
});
