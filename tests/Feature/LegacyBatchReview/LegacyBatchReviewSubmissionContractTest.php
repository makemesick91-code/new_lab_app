<?php

/**
 * BUGFIX-LEGACY-BATCH-REVIEW-DECISION-NOT-SUBMITTED-1 — the Blade -> JS -> server
 * submission contract.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * PR1's suites are thorough about the SERVER contract: per-item identity,
 * untouched documents, triage, separation of duties, authorization. Every one of
 * them POSTs a payload that already carries a valid `decision`, so none of them
 * can see a client that serializes an empty field — which is exactly what
 * shipped. The operator pressed "Tandai Ditinjau" and the server answered
 * "keputusan tinjauan wajib diisi.", correctly, about a request the browser had
 * built wrong.
 *
 * The ordering itself is proven where it lives, in
 * `tests/js/legacy-batch-review.test.mjs`, against the real factory. What THIS
 * file pins is the half a JS test cannot see: that the view actually wires that
 * factory to a form the browser can serialize. A perfect module is worthless if
 * the Blade forgets `x-ref`, or if a decision button goes back to being a native
 * submit that fires before any value is written.
 *
 * Both archives are asserted separately. They share one view today, and that is
 * precisely why neither may be taken on trust: a change made for one is a change
 * made for both, and only an explicit assertion per archive says so.
 */

use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/** The review view both archives render. */
function lbrShowView(): string
{
    return (string) file_get_contents(
        resource_path('views/settings/rme/legacy-batch-review/show.blade.php')
    );
}

function lbrAppJs(): string
{
    return (string) file_get_contents(resource_path('js/app.js'));
}

/**
 * Assert that every control which RECORDS a decision is an explicit
 * type="button".
 *
 * Anchored on `mark('<DECISION>')` rather than on a visible label, for two
 * reasons learned while writing this: the Indonesian label "Tahan" also occurs
 * in the clear-triage control, which is legitimately a native submit for its own
 * form, and a label is copy that may be reworded without touching behaviour.
 * The click handler IS the behaviour.
 */
function lbrAssertDecisionControlsAreNotNativeSubmits(string $html): void
{
    foreach (LegacyBatchReviewDecision::all() as $decision) {
        $needle = 'x-on:click="mark(\''.$decision.'\')"';
        $position = strpos($html, $needle);

        expect($position)->not->toBeFalse("no control records the {$decision} decision");

        // The opening tag that carries this handler.
        $openingTag = strrpos(substr($html, 0, $position), '<button');

        expect($openingTag)->not->toBeFalse("the {$decision} control is not a button");

        $tag = substr($html, $openingTag, $position - $openingTag);

        // A <button> with no type defaults to submit, which would post the form
        // natively before the decision was ever written into the field.
        // NOTE: toContain() is variadic — a second argument would be read as a
        // second needle, not a message, and would always fail.
        expect($tag)->toContain('type="button"');
    }
}

/*
|--------------------------------------------------------------------------
| The rendered form must be serializable — RME
|--------------------------------------------------------------------------
*/

it('renders the RME decision field with the ref the submit path writes to', function () {
    $uploader = superAdmin();
    $import = lbrRmeReady($uploader);
    $reviewer = lbrReviewer();
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    $response = $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-imports.show', $session->uuid))
        ->assertOk();

    // The field the browser serializes, and the handle the component writes it
    // through. Without the ref the submit path refuses outright rather than
    // posting an unverifiable decision — so this is load-bearing, not cosmetic.
    $response->assertSee('name="decision"', false);
    $response->assertSee('x-ref="decisionField"', false);
    $response->assertSee('x-ref="form"', false);

    // And the document this attestation is about, rendered server-side.
    $response->assertSee('name="import_id"', false);
    $response->assertSee('value="'.$import->getKey().'"', false);

    // The keyboard layer must be WIRED, not merely implemented. The shortcuts
    // live in the module and are proven there; if this binding is dropped the
    // whole keyboard path dies silently and every JS test still passes.
    $response->assertSee('x-data="legacyBatchReview(', false);
    $response->assertSee('@keydown.window="onKey($event)"', false);
});

it('renders the RME decision controls as type="button" so none submits before the value is written', function () {
    lbrRmeReady(superAdmin());
    $reviewer = lbrReviewer();
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    $html = $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-imports.show', $session->uuid))
        ->assertOk()
        ->getContent();

    lbrAssertDecisionControlsAreNotNativeSubmits($html);
});

/*
|--------------------------------------------------------------------------
| The rendered form must be serializable — Odontogram (asserted, not assumed)
|--------------------------------------------------------------------------
*/

it('renders the odontogram decision field with the ref the submit path writes to', function () {
    $uploader = superAdmin();
    $import = lbrOdontogramReady($uploader);
    $reviewer = lbrOdontogramReviewer();
    $session = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);

    $response = $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-odontograms.show', $session->uuid))
        ->assertOk();

    $response->assertSee('name="decision"', false);
    $response->assertSee('x-ref="decisionField"', false);
    $response->assertSee('x-ref="form"', false);
    $response->assertSee('name="import_id"', false);
    $response->assertSee('value="'.$import->getKey().'"', false);

    $response->assertSee('x-data="legacyBatchReview(', false);
    $response->assertSee('@keydown.window="onKey($event)"', false);
});

it('renders the odontogram decision controls as type="button" too', function () {
    lbrOdontogramReady(superAdmin());
    $reviewer = lbrOdontogramReviewer();
    $session = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);

    $html = $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-odontograms.show', $session->uuid))
        ->assertOk()
        ->getContent();

    lbrAssertDecisionControlsAreNotNativeSubmits($html);
});

/*
|--------------------------------------------------------------------------
| One implementation of the decision encoding
|--------------------------------------------------------------------------
*/

it('defines the reviewer component once, in a module the tests can drive', function () {
    $view = lbrShowView();
    $app = lbrAppJs();

    // The view asks Alpine for the component by name...
    expect($view)->toContain('x-data="legacyBatchReview(');

    // ...and app.js is what answers, from the extracted module. A name that
    // existed in only one of the two would leave the buttons inert — the
    // silent-failure shape this codebase has already been bitten by.
    expect($app)->toContain("Alpine.data('legacyBatchReview'")
        ->and($app)->toContain("from './legacy-batch-review'");

    // The component must NOT also live inline in the view. Two copies means two
    // decision encodings, and the one nobody tests is the one that breaks.
    expect($view)->not->toContain('function legacyBatchReview')
        ->and($view)->not->toContain('form.submit()');
});

it('keeps the decision vocabulary identical on both sides of the wire', function () {
    $module = (string) file_get_contents(resource_path('js/legacy-batch-review.js'));

    // The client may not invent, rename or extend the server's decision set.
    foreach (LegacyBatchReviewDecision::all() as $decision) {
        expect($module)->toContain("'".$decision."'");
    }

    // Nothing resembling a bulk vocabulary may appear client-side either.
    foreach (['ALL', 'markAll', 'reviewAll', 'selectAll'] as $forbidden) {
        expect($module)->not->toContain($forbidden);
    }
});

/*
|--------------------------------------------------------------------------
| The server stays the authority — this bugfix weakens nothing
|--------------------------------------------------------------------------
*/

it('still refuses an RME attestation that carries no decision', function () {
    $uploader = superAdmin();
    $import = lbrRmeReady($uploader);
    $session = lbrOpenSession(lbrRmeAdapter(), lbrReviewer());

    foreach ([[], ['decision' => ''], ['decision' => 'reviewed'], ['decision' => 'ALL']] as $payload) {
        $this->actingAs(lbrReviewer())
            ->post(
                route('settings.rme.legacy-review-imports.decide', $session->uuid),
                array_merge(['import_id' => $import->getKey()], $payload)
            )
            ->assertSessionHasErrors('decision');
    }
});

it('still refuses an odontogram attestation that carries no decision', function () {
    $uploader = superAdmin();
    $import = lbrOdontogramReady($uploader);
    $session = lbrOpenSession(lbrOdontogramAdapter(), lbrOdontogramReviewer());

    foreach ([[], ['decision' => ''], ['decision' => 'reviewed'], ['decision' => 'ALL']] as $payload) {
        $this->actingAs(lbrOdontogramReviewer())
            ->post(
                route('settings.rme.legacy-review-odontograms.decide', $session->uuid),
                array_merge(['import_id' => $import->getKey()], $payload)
            )
            ->assertSessionHasErrors('decision');
    }
});

it('accepts the payload the fixed client now sends, for both archives', function () {
    // RME.
    $rmeImport = lbrRmeReady(superAdmin());
    $rmeSession = lbrOpenSession(lbrRmeAdapter(), lbrReviewer());

    $this->actingAs(lbrReviewer())
        ->post(route('settings.rme.legacy-review-imports.decide', $rmeSession->uuid), [
            'import_id' => $rmeImport->getKey(),
            'decision' => LegacyBatchReviewDecision::REVIEWED,
            'pages_viewed' => 2,
        ])
        ->assertSessionHasNoErrors();

    // Odontogram.
    $odontogramImport = lbrOdontogramReady(superAdmin());
    $odontogramSession = lbrOpenSession(lbrOdontogramAdapter(), lbrOdontogramReviewer());

    $this->actingAs(lbrOdontogramReviewer())
        ->post(route('settings.rme.legacy-review-odontograms.decide', $odontogramSession->uuid), [
            'import_id' => $odontogramImport->getKey(),
            'decision' => LegacyBatchReviewDecision::REVIEWED,
            'pages_viewed' => 1,
        ])
        ->assertSessionHasNoErrors();
});
