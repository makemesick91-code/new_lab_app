<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — the §6 revalidation matrix.
 *
 * §6 lists the live state that must be re-checked immediately before EACH
 * publish. This suite drives each of those guards individually and asserts two
 * things every time: the document is NOT published, and the refusal carries the
 * specific §11 reason code an operator can act on.
 *
 * Every guard exercised here belongs to the CANONICAL layer. Batch publish
 * re-expresses none of them — these tests prove the batch path genuinely
 * reaches them, which is the only property worth asserting. A test that mocked
 * a guard would prove the mock.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/../LegacyBatchReview/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

/**
 * Select then publish one import and return its attempt row.
 *
 * Deliberately goes through the real selection + publish path rather than
 * calling the adapter directly, so each assertion is about what an OPERATOR
 * would actually get.
 */
function lbpGuardAttempt(object $import, ?object $publisher = null): LegacyBatchPublishItem
{
    $publisher ??= superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    return LegacyBatchPublishItem::where('rme_legacy_import_id', $import->getKey())->sole();
}

/*
|--------------------------------------------------------------------------
| Date rules, and the PRECISE native-boundary code
|--------------------------------------------------------------------------
*/

it('refuses a document whose legacy date is no longer before the native RME boundary', function () {
    // The native boundary is STRICT: a legacy date equal to or later than the
    // patient's earliest native encounter overlaps real history. Here a NEW,
    // EARLIER native visit appears after the document was reviewed, which is
    // precisely the drift §6 re-checks for.
    $import = lbpRmeReviewed(null, null, '2020-05-01');
    $patient = $import->patient;

    legacyRmeNativeVisit($patient, '2019-01-01');

    $item = lbpGuardAttempt($import);

    // The PRECISE code, not the generic date failure — this is what the
    // pre-flight exists to recover, because a canonical ValidationException
    // cannot carry the rule code.
    expect($item->reason_code)->toBe(LegacyBatchPublishReason::NATIVE_BOUNDARY_FAILED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses a document whose legacy date is no longer before the patient birth date', function () {
    // A different date rule, so it must report the GENERIC date code rather
    // than the native-boundary one — proving the two are genuinely
    // distinguished and not collapsed.
    $import = lbpRmeReviewed(null, null, '2020-05-01');

    $import->patient->forceFill(['date_of_birth' => '2021-01-01'])->save();

    $item = lbpGuardAttempt($import);

    expect($item->reason_code)->toBe(LegacyBatchPublishReason::DATE_RULE_FAILED)
        ->and($item->reason_code)->not->toBe(LegacyBatchPublishReason::NATIVE_BOUNDARY_FAILED);

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Branch
|--------------------------------------------------------------------------
*/

it('refuses a document whose origin branch no longer matches the patient medical record number', function () {
    // The origin-branch drift check: the archive was filed against one branch,
    // and the patient's Nomor RM now resolves to another.
    $import = lbpRmeReviewed();

    $other = legacyRmeBranch('LDK2', 'Cabang Landak');
    $import->forceFill(['origin_branch_id' => $other->id])->save();

    $item = lbpGuardAttempt($import->refresh());

    expect($item->reason_code)->toBe(LegacyBatchPublishReason::BRANCH_REFUSED);
    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses a document that has left the publisher branch scope entirely', function () {
    // A branch that stops being RME-enabled takes its documents out of scope.
    // Resolved as ABSENCE, which is what the HTTP layer turns into a 404 so an
    // actor cannot probe which ids exist elsewhere — and which means there is
    // no in-scope clinical row to attach an attempt to, so none is recorded.
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    Branch::query()
        ->whereKey($import->origin_branch_id)
        ->update(['is_rme_enabled' => false]);

    // Out of scope now.
    expect(lbpRmeAdapter()->findInScope($publisher, (int) $import->getKey()))->toBeNull();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $summary = app(LegacyBatchPublishRunService::class)
        ->select($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    expect($summary['eligible'])->toBe(0)
        ->and($summary['unavailable'])->toBe(1);

    // Nothing selected, nothing published, and the document is untouched.
    expect(LegacyBatchPublishItem::count())->toBe(0);
    expect(LegacyRmeRecord::count())->toBe(0);
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

/*
|--------------------------------------------------------------------------
| Source-patient binding
|--------------------------------------------------------------------------
*/

it('refuses a document whose source medical record number no longer matches the patient', function () {
    // LEGACY-RME-SOURCE-RM-BINDING-1: the Nomor RM the operator confirmed
    // reading ON the document is immutable evidence of what the document
    // asserts about itself. If it stops matching the patient it is filed
    // against, the archive must not be published to them.
    //
    // THE BINDING IS ISOLATED DELIBERATELY. An earlier version of this test
    // changed the PATIENT's record number, which breaks the binding AND the
    // branch-code resolution — so it accepted either refusal code, and a
    // mutation run proved that deleting the binding check entirely still passed
    // because BRANCH_REFUSED fired instead. Drifting the IMPORT's stored source
    // RM leaves the patient (and therefore the branch) untouched, so the
    // binding is the only thing that can refuse.
    $import = lbpRmeReviewed();

    // DERIVED from the patient's own record number rather than hardcoded. A
    // fixed literal could, in a full-suite run where the shared fixture
    // sequence has advanced, coincide with this patient's real number — and
    // the binding would then still match, quietly turning this into a test of
    // nothing.
    $drifted = (string) $import->patient->medical_record_number.'-X';

    $import->forceFill([
        'source_rm_raw' => $drifted,
        'source_rm_normalized' => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($drifted)),
    ])->save();

    expect($import->refresh()->source_rm_normalized)
        ->not->toBe($import->patient->medical_record_number);

    $item = lbpGuardAttempt($import->refresh());

    // EXACTLY the binding code — no alternatives accepted.
    expect($item->reason_code)->toBe(LegacyBatchPublishReason::PATIENT_BINDING_FAILED);
    expect($item->status)->toBe(LegacyBatchPublishItemStatus::REFUSED);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A NOTE ON THE ONE SURVIVING MUTANT, so nobody chases it again
|--------------------------------------------------------------------------
|
| A mutation run that deletes the patient-binding check from the BATCH
| ADAPTER'S PRE-FLIGHT (LegacyRmeBatchPublishAdapter::revalidate) SURVIVES this
| suite, and that is correct rather than a hole.
|
| The canonical publish asserts the same binding independently, inside its own
| transaction under the row lock (LegacyRmePublishService::publishWithinTransaction
| -> assertSourcePatientBindingStillValid). So deleting the adapter's copy
| changes nothing an operator can observe: the document is still refused, with
| the same PATIENT_BINDING_FAILED code, because the classifier maps the
| canonical refusal's field key to it. The pre-flight exists for the §12
| "eligible now" count and for precise codes, not to enforce.
|
| That equivalence was PROVEN, not assumed. Two stronger mutants were run:
| removing the assertion from the canonical publish, and removing it from both
| layers at once. BOTH ARE KILLED by this suite. So the guard is genuinely
| enforced and genuinely tested; only the redundant copy is unobservable.
|
| The lesson for future mutation work on this module: target the CANONICAL
| layer. A mutant aimed at a deliberately-redundant pre-flight measures nothing.
*/

/*
|--------------------------------------------------------------------------
| Single-active-document
|--------------------------------------------------------------------------
*/

it('files at most one published archive per patient per document type', function () {
    // REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1. The slot is held by
    // the staging row for its whole life and then by the published record, so a
    // patient cannot accumulate two concurrent RME lifecycles — which is what
    // makes a second published record for the same patient unreachable through
    // batch publish.
    //
    // The assertion is about the INVARIANT, not about which canonical guard
    // happens to fire first. A second intake attempt is refused by whichever of
    // the slot guard, the date rules or the duplicate check gets there first;
    // pinning one exception type would make this test a statement about guard
    // ORDERING rather than about the one-archive-per-patient rule.
    $publisher = superAdmin();
    $first = lbpRmeReviewed();
    $patientId = (int) $first->patient_id;
    $patient = $first->patient;

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $first->getKey()]);

    expect(LegacyRmeRecord::where('patient_id', $patientId)->count())->toBe(1);

    // The slot is now held by the published record.
    $occupancy = app(LegacySingleActiveDocumentService::class)
        ->occupancyFor(LegacyImportType::LEGACY_RME, $patientId);

    expect($occupancy->occupied)->toBeTrue();

    // A second intake for the same patient is refused — some canonical guard
    // stops it, and the invariant holds regardless of which.
    $second = null;

    try {
        app(LegacyRmeImportService::class)->createFromUpload(
            $patient->refresh(),
            '2019-01-01',
            $patient->medical_record_number,
            null,
            lbrRmeUpload(),
            superAdmin(),
        );
    } catch (Throwable $refusal) {
        $second = $refusal;
    }

    expect($second)->not->toBeNull('a second lifecycle for this patient must be refused');

    // STILL exactly one published archive for this patient.
    expect(LegacyRmeRecord::where('patient_id', $patientId)->count())->toBe(1);
    expect(LegacyRmeRecord::where('patient_id', $patientId)->where('status', 'PUBLISHED')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Source and page integrity
|--------------------------------------------------------------------------
*/

it('refuses a document whose rendered pages have gone missing', function () {
    // assertRenderedPagesUsable / assertSourceStillPresent in the canonical
    // publish. Wiping the private disk simulates bytes that vanished between
    // review and publish — the archive must not be filed pointing at nothing.
    $import = lbpRmeReviewed();

    Storage::fake('legacy_rme_private');

    $item = lbpGuardAttempt($import);

    // Reported as a stale/changed-source refusal rather than published.
    expect($item->reason_code)->toBeIn([
        LegacyBatchPublishReason::STALE_ITEM,
        LegacyBatchPublishReason::SOURCE_CHANGED,
    ]);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

it('refuses a publisher who lacks the canonical publish permission', function () {
    $import = lbpRmeReviewed();

    // Holds review but NOT publish. The route middleware would stop them
    // earlier over HTTP; this proves the service-level backstop too.
    $reviewerOnly = userWith(['view_legacy_rme_imports', 'review_legacy_rme_imports']);

    $item = lbpGuardAttempt($import, $reviewerOnly);

    expect($item->reason_code)->toBeIn([
        LegacyBatchPublishReason::AUTHORIZATION_REFUSED,
        LegacyBatchPublishReason::IMPORT_UNAVAILABLE,
    ]);

    expect(LegacyRmeRecord::count())->toBe(0);
});
