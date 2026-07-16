<?php

use App\Modules\Satusehat\Gateways\FakeSatusehatGateway;
use App\Modules\Satusehat\Gateways\SatusehatApiResponse;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Jobs\PrepareSatusehatSubmissionBatchJob;
use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Services\SatusehatSubmissionProcessingService;
use App\Modules\Satusehat\Services\SatusehatSubmissionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    config()->set('satusehat.candidate.auto_generate', false);
    ssConfigureSandbox();
});

/**
 * @return array{ctx: array, batch: SatusehatSubmissionBatch, encounter: SatusehatSubmissionItem}
 */
function ssPreparedBatch(): array
{
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $candidate = ssService()->generateForVisit($ctx['visit']);
    ssService()->approve($candidate, userWith(['review_satusehat_submissions']));

    $branchIds = [$ctx['branch']->id];
    $batch = app(SatusehatSubmissionService::class)->prepare([$candidate->id], $branchIds, userWith(['send_satusehat_submissions']));

    // Simulate the PrepareSatusehatSubmissionBatchJob's prepared → queued step so
    // processItem() can claim (items must be QUEUED before processing).
    $batch->items()->update(['status' => SatusehatSubmissionItem::STATUS_QUEUED]);
    $encounter = $batch->items()->where('resource_type', SatusehatSubmissionItem::RESOURCE_ENCOUNTER)->firstOrFail();

    return compact('ctx', 'batch', 'encounter');
}

function ssBindFakeGateway(bool $enabled = true): FakeSatusehatGateway
{
    $fake = new FakeSatusehatGateway($enabled);
    app()->instance(SatusehatGatewayInterface::class, $fake);

    return $fake;
}

it('refuses to queue a batch while the gateway is disabled (fail-closed)', function () {
    $prepared = ssPreparedBatch();
    ssBindFakeGateway(enabled: false);

    app(SatusehatSubmissionService::class)->queue($prepared['batch'], [$prepared['ctx']['branch']->id], userWith(['send_satusehat_submissions']));
})->throws(ValidationException::class);

it('queues an enabled batch and dispatches the driver job after commit', function () {
    Bus::fake();
    $prepared = ssPreparedBatch();
    ssBindFakeGateway(enabled: true);

    app(SatusehatSubmissionService::class)->queue($prepared['batch'], [$prepared['ctx']['branch']->id], userWith(['send_satusehat_submissions']));

    Bus::assertDispatched(PrepareSatusehatSubmissionBatchJob::class);
    expect($prepared['batch']->fresh()->status)->toBe(SatusehatSubmissionBatch::STATUS_QUEUED);
});

it('refuses to queue a batch outside the actor branch scope (IDOR)', function () {
    $prepared = ssPreparedBatch();
    ssBindFakeGateway(enabled: true);

    app(SatusehatSubmissionService::class)->queue($prepared['batch'], [999999], userWith(['send_satusehat_submissions']));
})->throws(ValidationException::class);

it('processes an Encounter to succeeded and stores the remote id', function () {
    $prepared = ssPreparedBatch();
    ssBindFakeGateway(enabled: true);

    $item = app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'corr-1');

    expect($item->status)->toBe(SatusehatSubmissionItem::STATUS_SUCCEEDED)
        ->and($item->remote_resource_id)->not->toBeNull();
});

it('never resends a succeeded resource (idempotent)', function () {
    $prepared = ssPreparedBatch();
    $fake = ssBindFakeGateway(enabled: true);

    app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'c');
    app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'c');

    expect(count($fake->createdResources))->toBe(1); // second run is a no-op
});

it('marks a retryable failure as retryable_failed with a next attempt', function () {
    $prepared = ssPreparedBatch();
    $fake = ssBindFakeGateway(enabled: true);
    $fake->scriptResponse('Encounter', SatusehatApiResponse::retryable('slow', 503, 'c'));

    $item = app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'c');

    expect($item->status)->toBe(SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED)
        ->and($item->next_attempt_at)->not->toBeNull();
});

it('marks an ambiguous outcome as unknown, then reconciles via GET', function () {
    $prepared = ssPreparedBatch();
    $fake = ssBindFakeGateway(enabled: true);
    // First the create is ambiguous, but a remote id was captured for GET.
    $fake->scriptResponse('Encounter', SatusehatApiResponse::unknown('timeout', 500, 'c'));

    $item = app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'c');
    expect($item->status)->toBe(SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME);

    // Give it a remote id so reconciliation can GET it, then reconcile (GET → success).
    $item->update(['remote_resource_id' => 'enc-remote-x']);
    $reconciled = app(SatusehatSubmissionProcessingService::class)->reconcileItem($item->id, 'c');

    expect($reconciled->status)->toBe(SatusehatSubmissionItem::STATUS_RECONCILED);
});

it('aborts submission when the clinical source drifted after preparation', function () {
    $prepared = ssPreparedBatch();
    ssBindFakeGateway(enabled: true);

    // Mutate clinical data → the re-evaluated source hash no longer matches.
    $prepared['ctx']['patient']->update(['name' => 'Nama Berubah Total']);

    $item = app(SatusehatSubmissionProcessingService::class)->processItem($prepared['encounter']->id, 'c');

    expect($item->status)->toBe(SatusehatSubmissionItem::STATUS_PERMANENT_FAILED);
});
