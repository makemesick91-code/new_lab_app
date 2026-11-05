<?php

use App\Models\User;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Support\Routing\DatabaseRouteKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-3 / R-10 — the receipt reprint route takes only a
| receipt snapshot key.
|--------------------------------------------------------------------------
|
| `rme/cashier/{clinicVisit}/billing/{rmeInvoice}/receipts/{receiptSnapshot}`
| is not model-bound on the snapshot: the controller takes
| `int $receiptSnapshot` and looks the row up by its bigint `id`. The route
| carried `->whereNumber('receiptSnapshot')` (`[0-9]+`), which admits:
|   - a number beyond PHP's integer range, which cannot be passed to the
|     `int` argument (TypeError: HTTP 500 plus an ERROR line); and
|   - a leading-zero alias (`0` . id), which PHP casts to the real id, so a
|     non-canonical URL rendered a real issued receipt.
|
| `receiptSnapshot` is a domain-specific name with exactly one route, so it
| joins DatabaseRouteKey::CONSTRAINED_PARAMETERS and the looser per-route
| override is removed (rule 222 §1.4). The snapshot itself is immutable
| evidence of what the patient was given; nothing here writes to it.
|
| Every behavioural case is driver-independent: the defect is in PHP's
| integer conversion, not in a database cast.
*/

require_once __DIR__.'/postpayment-helpers.php';

beforeEach(function () {
    ricSetup();
    rppFlag(true);

    $this->invoice = rppPaidInvoice(500000);
    $this->receipt = RmeReceiptSnapshot::query()->where('rme_invoice_id', $this->invoice->id)->sole();
});

/** @return array<string, string> already URL-encoded segments that are not a key */
function rskNonKeySegments(): array
{
    return [
        'letters' => 'abc',
        'template placeholder' => '%7BRECEIPT_ID%7D',
        'negative number' => '-1',
        'zero' => '0',
        'leading zero' => '007',
        'decimal' => '1.5',
        'invalid percent encoding' => '%ZZ',
        'nineteen digits' => '1000000000000000000',
        'beyond PHP integer range' => '99999999999999999999',
    ];
}

/** @return ArrayObject<int, string> */
function rskCaptureErrorLogs(): ArrayObject
{
    $levels = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($levels): void {
        if (in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
            $levels->append($event->level.': '.mb_substr($event->message, 0, 120));
        }
    });

    return $levels;
}

function rskUrl(RmeInvoice $invoice, string $segment): string
{
    return '/rme/cashier/'.$invoice->clinic_visit_id.'/billing/'.$invoice->id.'/receipts/'.$segment;
}

it('constrains the receipt reprint route with the canonical database key pattern', function () {
    $route = Route::getRoutes()->getByName('rme.cashier.receipt-snapshots.show');

    // `whereNumber` would leave `[0-9]+`, which admits integer overflow.
    expect($route)->not->toBeNull()
        ->and($route->wheres['receiptSnapshot'] ?? null)->toBe(DatabaseRouteKey::PATTERN)
        ->and(DatabaseRouteKey::CONSTRAINED_PARAMETERS)->toContain('receiptSnapshot');
});

it('keeps the receipt snapshot constraint in the compiled collection that route:cache writes', function () {
    $compiled = app('router')->getRoutes()->compile();

    $withParameter = collect($compiled['attributes'])
        ->filter(fn (array $attributes) => str_contains((string) $attributes['uri'], '{receiptSnapshot}'));

    expect($withParameter)->toHaveCount(1)
        ->and($withParameter->first()['wheres']['receiptSnapshot'] ?? null)->toBe(DatabaseRouteKey::PATTERN);
});

it('answers 404 with no ERROR to the cashier for every segment that is not a key', function () {
    $errors = rskCaptureErrorLogs();
    ricActingAs(test()->cashier);

    $mismatched = [];
    foreach (rskNonKeySegments() as $label => $segment) {
        $status = $this->get(rskUrl($this->invoice, $segment))->getStatusCode();

        if ($status !== 404) {
            $mismatched[] = "{$label} => {$status}";
        }
    }

    expect($mismatched)->toBe([])
        ->and($errors->getArrayCopy())->toBe([]);
});

it('answers 404 to a guest for a segment that is not a key and redirects a well-formed one to login', function () {
    // The paid-invoice fixture signs the cashier in to record the payment, so
    // the session must be dropped here or this would not be a guest at all.
    $this->app['auth']->forgetGuards();
    expect(auth()->check())->toBeFalse();

    foreach (rskNonKeySegments() as $label => $segment) {
        expect($this->get(rskUrl($this->invoice, $segment))->getStatusCode())->toBe(404, $label);
    }

    $this->get(rskUrl($this->invoice, (string) $this->receipt->id))->assertRedirect();
});

it('reprints the real receipt only at its canonical key, never at a leading-zero alias', function () {
    ricActingAs(test()->cashier);

    $this->get(rskUrl($this->invoice, (string) $this->receipt->id))->assertOk();
    // Before this revision `0<id>` cast to the real id and rendered the receipt.
    $this->get(rskUrl($this->invoice, '0'.$this->receipt->id))->assertNotFound();
    $this->get(rskUrl($this->invoice, '00'.$this->receipt->id))->assertNotFound();
});

it('keeps the receipt policy, the invoice ownership check and the snapshot untouched', function () {
    $digestBefore = $this->receipt->fresh()->snapshot_sha256;

    // A user who may not view receipts meets the policy, not a 500.
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get(rskUrl($this->invoice, (string) $this->receipt->id))->assertForbidden();

    // A snapshot of another invoice is still a 404 on this invoice's URL.
    $second = rppPaidInvoice(250000);
    $foreign = RmeReceiptSnapshot::query()->where('rme_invoice_id', $second->id)->sole();
    ricActingAs(test()->cashier);
    $this->get(rskUrl($this->invoice, (string) $foreign->id))->assertNotFound();

    // The longest key the pattern admits names no snapshot: 404, no 500.
    $this->get(rskUrl($this->invoice, '999999999999999999'))->assertNotFound();

    expect($this->receipt->fresh()->snapshot_sha256)->toBe($digestBefore);
});
