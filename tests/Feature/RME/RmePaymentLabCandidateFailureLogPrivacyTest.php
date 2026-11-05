<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-1 / R-02 — the lab-candidate failure log carries
| no clinical values.
|--------------------------------------------------------------------------
|
| After an RME payment commits, RmePaymentService generates lab case
| candidates and, by design (Sprint 21 Phase 21.2), a failure there is logged
| and never rolls the payment back. The warning used to carry
| `$e->getMessage()` verbatim. A QueryException message ends with
| " (Connection: …, SQL: …)" with every binding interpolated, and PostgreSQL
| adds "DETAIL: Key (col)=(value)" / "Failing row contains (…)" lines, so a
| failed insert could write patient values into the application log (CG-11).
|
| The warning now runs the message through the canonical TelemetryRedactor
| and records the exception class, which is enough to diagnose the failure.
| Every value below is synthetic.
*/

const LCF_PATIENT_NAME = 'Siti Uji Sintetis';
const LCF_NIK = '3171234567890001';
const LCF_RM = 'DG-SPN4-2026-0042';
const LCF_BIRTH_DATE = '1990-05-17';
const LCF_WARNING = 'Lab case candidate generation failed after RME payment';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
});

function lcfUnpaidInvoice(Branch $branch, User $cashier): RmeInvoice
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    rmeSignedConsentFor($visit);

    return app(RmeInvoiceService::class)->create($visit, $cashier, ['items' => [[
        'treatment_id' => null,
        'description' => 'Perawatan Gigi',
        'qty' => 1,
        'unit_price' => 300000,
        'discount' => 0,
    ]]])->refresh();
}

/** Make the post-payment lab hook fail with $failure. */
function lcfFailLabGenerationWith(Throwable $failure): void
{
    app()->instance(RmeLabIntegrationService::class, new class($failure, app(BranchService::class)) extends RmeLabIntegrationService
    {
        public function __construct(private readonly Throwable $failure, BranchService $branches)
        {
            parent::__construct($branches);
        }

        public function generateForPaidInvoice(RmeInvoice $invoice, ?User $actor = null): Collection
        {
            throw $this->failure;
        }
    });
}

/**
 * Pay $invoice in full and return the context of the lab-failure warning.
 *
 * @return array<string, mixed>
 */
function lcfPayAndCaptureWarning(RmeInvoice $invoice, User $cashier): array
{
    $warnings = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings): void {
        if ($event->level === 'warning' && $event->message === LCF_WARNING) {
            $warnings->append($event->context);
        }
    });

    app(RmePaymentService::class)->pay($invoice, $cashier, rmeFreshPaymentForm($invoice, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]));

    expect($warnings)->toHaveCount(1);

    return $warnings[0];
}

function lcfQueryFailure(): QueryException
{
    $driverError = new PDOException(
        'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint '
        .'"trx_lab_case_candidates_rme_invoice_item_id_unique"'."\n"
        .'DETAIL:  Key (rme_invoice_item_id)=(4242) already exists.'."\n"
        .'Failing row contains (4242, '.LCF_PATIENT_NAME.', '.LCF_NIK.').'
    );

    return new QueryException(
        'pgsql',
        'insert into "trx_lab_case_candidates" ("patient_name", "nik", "medical_record_number", "date_of_birth") values (?, ?, ?, ?)',
        [LCF_PATIENT_NAME, LCF_NIK, LCF_RM, LCF_BIRTH_DATE],
        $driverError,
    );
}

/**
 * Scan only what the failure contributed (`error`, `exception`). The context
 * also carries `rme_invoice_id`, and PostgreSQL sequences survive
 * RefreshDatabase, so a long run could mint an id that contains a forbidden
 * digit string and fail with no leak at all.
 *
 * @param  array<string, mixed>  $context
 */
function lcfAssertNoClinicalValue(array $context): void
{
    expect(array_keys($context))->toEqualCanonicalizing(['rme_invoice_id', 'exception', 'error']);

    $logged = json_encode([$context['exception'], $context['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ([LCF_PATIENT_NAME, LCF_NIK, LCF_RM, LCF_BIRTH_DATE, '4242', 'SQL:', 'Connection:', 'DETAIL', 'Failing row'] as $forbidden) {
        expect($logged)->not->toContain($forbidden);
    }
}

it('logs a database failure without the bindings, the DETAIL row or the SQL tail', function () {
    $invoice = lcfUnpaidInvoice($this->branch, $this->cashier);
    lcfFailLabGenerationWith(lcfQueryFailure());

    $context = lcfPayAndCaptureWarning($invoice, $this->cashier);

    lcfAssertNoClinicalValue($context);

    // Still diagnosable: which invoice, which exception, which SQLSTATE.
    expect($context['rme_invoice_id'])->toBe((int) $invoice->id)
        ->and($context['exception'])->toBe(QueryException::class)
        ->and($context['error'])->toContain('SQLSTATE[23505]');
});

// The redactor removes QUOTED literals, NIK/phone digit runs, Nomor RM and
// dates from any message. It cannot recognise an UNQUOTED free-text name; no
// current throw site under lab-candidate generation produces one (rule 222 §2).
it('redacts quoted values, NIK, Nomor RM and dates from a non-database failure too', function () {
    $invoice = lcfUnpaidInvoice($this->branch, $this->cashier);
    lcfFailLabGenerationWith(new RuntimeException(
        "Gagal membuat kandidat untuk pasien '".LCF_PATIENT_NAME."' NIK ".LCF_NIK.' RM '.LCF_RM.' lahir '.LCF_BIRTH_DATE
    ));

    $context = lcfPayAndCaptureWarning($invoice, $this->cashier);

    lcfAssertNoClinicalValue($context);
    expect($context['exception'])->toBe(RuntimeException::class)
        ->and($context['error'])->toContain('Gagal membuat kandidat');
});

it('still never rolls the payment back when lab-candidate generation fails', function () {
    $invoice = lcfUnpaidInvoice($this->branch, $this->cashier);
    lcfFailLabGenerationWith(lcfQueryFailure());

    lcfPayAndCaptureWarning($invoice, $this->cashier);

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});
