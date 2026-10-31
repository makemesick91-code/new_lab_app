<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — the branch
| reviewer designation (OPS-OQ-1a) and the Kontrol-only payment (OPS-OQ-1b)
| under REAL PostgreSQL concurrency (rule 204).
|--------------------------------------------------------------------------
|
| THE LOCK ORDERS UNDER TEST.
|   - Dispute action: INVOICE → DISPUTE → DESIGNATION (FOR SHARE). A revocation
|     takes only the designation (FOR UPDATE), so it either commits before the
|     action reads it or waits for the action — never a cycle.
|   - Kontrol-only payment: KONTROL INVOICE → each ORIGIN invoice (FOR UPDATE) —
|     exactly the order of the ordinary control payment, so the new path adds
|     no lock-order edge to the estate.
|
| THE LINEARIZATION POINTS. The reviewer's usability, permission, designation
| and working branch, and the cashier's usability and working branch, are all
| read AFTER the invoice lock. The origin's dispute state is read under the
| origin's own row lock. A competing transaction that committed before those
| reads decides the outcome; one that rolled back does not.
|
| THE PROOF. The competing transaction is a child PHP process
| (dispute_race_child.php) booting the REAL application and running the REAL
| services inside one transaction it holds open; the parent runs the other
| real service meanwhile. Every test asserts the parent genuinely WAITED, and
| that no outcome is a deadlock or other database error.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate()/sharedLock() to
| nothing; the property is unobservable there. The skip is stated, never a
| silent pass — and a skipped run is never reported as a concurrency PASS.
|
| WHY NO RefreshDatabase. A second session cannot see rows of a transaction
| that never commits. This suite commits for real and deletes what it created
| by id watermark — the pattern of RmeBillingDisputeConcurrencyTest.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=<db> \
|   DB_USERNAME=<user> DB_PASSWORD=<password> \
|   php artisan test tests/Feature/RmeBillingDispute/RmeBillingDisputeBranchReviewerConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeReviewer;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeReviewerService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RBRC_WRITER = 'rbrc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RBRC_TABLES = [
    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1: refund rows reference the
    // obligation, invoice and users with RESTRICT, so they go first.
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references', // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1: child of users only
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_billing_dispute_events',
    'trx_rme_billing_disputes',
    'mst_rme_billing_dispute_reviewers',
    'trx_branch_change_requests',
    'trx_daily_branch_contexts',
    'trx_user_online_contexts',
    // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — every payment issues a receipt
    // snapshot (FK to trx_rme_payments); obligations reference corrections.
    'trx_rme_overpayment_obligation_events',
    'trx_rme_overpayment_obligations',
    'trx_rme_receipt_snapshots',
    'trx_rme_invoice_correction_events',
    'trx_rme_invoice_correction_lines',
    'trx_rme_invoice_document_versions',
    'trx_rme_invoice_corrections',
    'trx_lab_case_candidates',
    'trx_rme_payment_submissions', // rule 205 — references trx_rme_payments
    'trx_rme_payments',
    'trx_rme_invoice_items',
    'trx_rme_invoices',
    'trx_rme_visit_consents',
    'trx_medical_records',
    'trx_clinic_visits',
    'mst_clinic_rooms',
    'mst_patients',
    'mst_doctors',
    'mst_clinics',
    'mst_treatments',
    'mst_treatment_categories',
    'mst_branches',
    'users',
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() and sharedLock() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RBRC_WRITER, $base);
    DB::purge(RBRC_WRITER);
    config()->set('database.default', RBRC_WRITER);

    seedAccessControl();
    ricFlag(true);

    $this->watermarks = [];
    foreach (RBRC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RBRC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RBRC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    foreach (['model_has_permissions', 'model_has_roles'] as $pivot) {
        $writer->table($pivot)
            ->where('model_type', User::class)
            ->where('model_id', '>', $this->watermarks['users'])
            ->delete();
    }
    $writer->table('notifications')
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RBRC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RBRC_WRITER);
});

/**
 * A committed Kontrol chain at one pilot branch: an origin visit with an UNPAID
 * 500.000 invoice A, and a Kontrol visit (follow-up of it) with an UNPAID
 * 500.000 invoice B. Plus a second RME branch, a cashier, a reviewer governor,
 * a designatable workspace account, a permission reviewer and a pilot maker /
 * checker pair.
 *
 * @return array<string, mixed>
 */
function rbrcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RC'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        $other = Branch::factory()->create(['code' => 'RO'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        $fx = [
            'branch' => $branch,
            'other' => $other,
            'patient' => Patient::factory()->create(['branch_id' => $branch->id]),
            'clinic' => Clinic::factory()->create(),
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'governor' => userWith(['manage_rme_billing_dispute_reviewers', 'view_clinic_visits']),
            'designee' => userWith(['view_clinic_visits']),
            'reviewer' => userWith(['review_rme_billing_disputes']),
            'maker' => $maker,
            'checker' => $checker,
        ];
        $fx['doctor'] = Doctor::factory()->create(['branch_id' => $branch->id]);

        $originVisit = rbrcVisit($fx, ClinicVisit::VISIT_TYPE_NEW, null);
        $controlVisit = rbrcVisit($fx, ClinicVisit::VISIT_TYPE_CONTROL, (int) $originVisit->id);
        $fx['origin'] = rbrcInvoice($fx, $originVisit);
        $fx['control'] = rbrcInvoice($fx, $controlVisit);

        return $fx;
    });
}

/** @param array<string, mixed> $fx */
function rbrcVisit(array $fx, string $type, ?int $followUpOf): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $fx['branch']->id,
        'clinic_id' => $fx['clinic']->id,
        'patient_id' => $fx['patient']->id,
        'doctor_id' => $fx['doctor']->id,
        'visit_type' => $type,
        'follow_up_of_visit_id' => $followUpOf,
        'visit_number' => 'VIS-RC-'.uniqid(),
        'visit_date' => clinicalToday()->toDateString(),
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id,
        'patient_id' => $fx['patient']->id, 'doctor_id' => $fx['doctor']->id,
    ]);

    return $visit;
}

/** @param array<string, mixed> $fx */
function rbrcInvoice(array $fx, ClinicVisit $visit): RmeInvoice
{
    $record = MedicalRecord::query()->where('clinic_visit_id', $visit->id)->firstOrFail();
    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $fx['branch']->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $fx['patient']->id,
        'medical_record_id' => $record->id, 'cashier_id' => $fx['cashier']->id,
        'subtotal' => 500000, 'discount_total' => 0, 'grand_total' => 500000,
    ]);
    foreach ([['Scaling', 300000], ['Tambal', 200000]] as [$desc, $price]) {
        $treatment = Treatment::factory()->create(['name' => $desc.' '.uniqid(), 'is_active' => true, 'requires_lab' => false]);
        DB::table('trx_rme_invoice_items')->insert([
            'rme_invoice_id' => $invoice->id, 'treatment_id' => $treatment->id, 'description' => $desc, 'qty' => 1,
            'unit_price' => $price, 'discount' => 0, 'subtotal' => $price,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $invoice->fresh();
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rbrcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RBRC_WRITER);

    $payload = base64_encode((string) json_encode([
        'steps' => $steps,
        'hold' => $hold,
        'finish' => $finish,
        'config' => [
            'feature_flags.flags' => config('feature_flags.flags'),
            'rme_invoice_correction.pilot.branch_codes' => config('rme_invoice_correction.pilot.branch_codes'),
            'rme_invoice_correction.pilot.identities' => config('rme_invoice_correction.pilot.identities'),
        ],
    ]));

    $env = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => (string) config('app.key'),
        'APP_URL' => (string) config('app.url'),
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/dispute_race_child.php', $payload],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        $env,
    );
    expect($process)->toBeResource();

    $line = trim((string) fgets($pipes[1]));
    if ($line !== 'LOCKED') {
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        test()->fail('The competing transaction did not take its locks: '.$line.' '.$err);
    }

    return [$process, $pipes, $finish];
}

/** @param array{0: resource, 1: array<int, resource>, 2: string} $handle */
function rbrcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

/**
 * The parent's competing action, its outcome and how long it waited. A database
 * error (a deadlock, a serialization failure) is captured as ERROR so a test
 * can assert there was none — never a silent pass.
 *
 * @return array{0: string, 1: float}
 */
function rbrcRace(callable $work): array
{
    $started = microtime(true);
    try {
        $work();
        $outcome = 'OK';
    } catch (RmeBillingDisputeDenied $e) {
        $outcome = 'DENIED:'.$e->reasonCode;
    } catch (ValidationException $e) {
        $outcome = 'REFUSED:'.json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    } catch (QueryException $e) {
        $outcome = 'ERROR:'.$e->getCode();
    }

    return [$outcome, microtime(true) - $started];
}

/** @param array<string, mixed> $fx */
function rbrcReport(array $fx, RmeInvoice $invoice): RmeBillingDispute
{
    test()->actingAs($fx['cashier']);

    return app(RmeBillingDisputeService::class)->report($invoice->fresh(), $fx['cashier'], [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ]);
}

/** @return array<string, mixed> */
function rbrcReportStep(RmeInvoice $invoice, User $reporter): array
{
    return [
        'kind' => 'report_dispute', 'invoice' => (int) $invoice->id, 'actor' => (int) $reporter->id,
        'reason' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ];
}

/** @param array<string, mixed> $fx */
function rbrcDesignate(array $fx, User $target): RmeBillingDisputeReviewer
{
    return app(RmeBillingDisputeReviewerService::class)
        ->designate($fx['governor'], $target, (int) $fx['branch']->id, 'Penanggung jawab cabang untuk peninjauan tagihan.');
}

/** @param array<string, mixed> $fx */
function rbrcDesignateStep(array $fx, User $target): array
{
    return [
        'kind' => 'designate_reviewer', 'actor' => (int) $fx['governor']->id, 'user' => (int) $target->id,
        'branch' => (int) $fx['branch']->id, 'reason' => 'Penanggung jawab cabang untuk peninjauan tagihan.',
    ];
}

function rbrcLockStep(RmeInvoice $invoice): array
{
    return ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $invoice->id]];
}

function rbrcPayForm(RmeInvoice $invoice, int $amount): array
{
    // Rule 205: the Kontrol-only form also carries a fresh intent and declares
    // the control_only plan with the Kontrol balance it displays.
    return rmeDeclaredPaymentForm($invoice, [
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => (int) $invoice->fresh()->correction_version],
        'payment_scope' => RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY,
        'confirm_control_only' => true,
    ]);
}

/** @param array<string, mixed> $fx */
function rbrcPayOnly(array $fx, int $amount = 500000, ?User $cashier = null): mixed
{
    $cashier ??= $fx['cashier'];
    test()->actingAs($cashier);

    return app(RmePaymentService::class)->payControlInvoiceOnly($fx['control']->fresh(), $cashier, rbrcPayForm($fx['control'], $amount));
}

function rbrcPayments(RmeInvoice $invoice): int
{
    return DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count();
}

/** The origin's money columns, which no Kontrol-only payment may ever move. */
function rbrcOriginMoney(RmeInvoice $origin): array
{
    $fresh = $origin->fresh();

    return [
        'status' => $fresh->status,
        'grand_total' => (float) $fresh->grand_total,
        'remaining' => (float) $fresh->remainingAmount(),
        'payments' => rbrcPayments($origin),
        'version' => (int) $fresh->correction_version,
    ];
}

/** @return array<string, mixed> */
function rbrcConfirmData(): array
{
    return [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Dicek dengan nota tindakan: harga sudah benar.',
        'resolution_reference' => 'OWNER-OK/2026-10-24/RACE-RBRC',
    ];
}

/** @return array<string, mixed> */
function rbrcCorrectionData(RmeInvoice $invoice, string $tambalPrice = '50000'): array
{
    $items = $invoice->items()->orderBy('id')->get();

    return [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'lines' => [
            ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
            ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => $tambalPrice, 'discount' => '0'],
        ],
    ];
}

/**
 * A context-bound account (Admin Klinik / Kasir) working at the fixture branch,
 * plus a committed PENDING branch-change request moving it to the other branch.
 *
 * @param  array<string, mixed>  $fx
 * @return array{0: User, 1: int}
 */
function rbrcContextBoundWithPendingTransfer(array $fx, string $role): array
{
    $user = userInRole($role);
    $role === 'Kasir'
        ? rmeMakeKasirActive($user, $fx['branch'])
        : rmeMakeAdminClinicActive($user, $fx['branch']);

    $request = app(BranchChangeApprovalService::class)
        ->request($user, (int) $fx['other']->id, 'Dipindah bertugas ke cabang lain hari ini.');

    return [$user->fresh(), (int) $request->id];
}

// ─── A: reviewer designation vs review ──────────────────────────────────────

it('A: a review waiting on a designation that commits is decided by it — allowed', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([rbrcLockStep($fx['origin']), rbrcDesignateStep($fx, $fx['designee'])]);

    test()->actingAs($fx['designee']);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $fx['designee']));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW);
});

it('A2: a review waiting on a designation that rolls back is refused — no authority was ever granted', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([rbrcLockStep($fx['origin']), rbrcDesignateStep($fx, $fx['designee'])], 'rollback');

    test()->actingAs($fx['designee']);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $fx['designee']));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('DENIED:'.RmeBillingDisputeDenied::PERMISSION_MISSING)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(RmeBillingDisputeReviewer::query()->where('user_id', $fx['designee']->id)->count())->toBe(0);
});

// ─── B: reviewer revocation vs review ───────────────────────────────────────

it('B: a review that reaches the designation while a revocation holds it waits, then is refused', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $designation = rbrcDesignate($fx, $fx['designee']);
    // No invoice lock in the competitor: the parent takes invoice and dispute
    // freely and blocks on the DESIGNATION row itself — the FOR SHARE read.
    $handle = rbrcCompeting([[
        'kind' => 'revoke_designation', 'actor' => (int) $fx['governor']->id,
        'designation' => (int) $designation->id, 'reason' => 'Penanggung jawab cabang diganti.',
    ]]);

    test()->actingAs($fx['designee']);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $fx['designee']));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('DENIED:'.RmeBillingDisputeDenied::PERMISSION_MISSING)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and($designation->fresh()->isActive())->toBeFalse();
});

it('B2: a review waiting on a revocation that rolls back proceeds under the still-active designation', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $designation = rbrcDesignate($fx, $fx['designee']);
    $handle = rbrcCompeting([[
        'kind' => 'revoke_designation', 'actor' => (int) $fx['governor']->id,
        'designation' => (int) $designation->id, 'reason' => 'Penanggung jawab cabang diganti.',
    ]], 'rollback');

    test()->actingAs($fx['designee']);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $fx['designee']));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::UNDER_REVIEW)
        ->and($designation->fresh()->isActive())->toBeTrue();
});

// ─── C: branch transfer vs review ───────────────────────────────────────────

it('C: a designated reviewer whose branch transfer commits while the review waits is refused — the designation does not follow the person', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    [$designee, $requestId] = rbrcContextBoundWithPendingTransfer($fx, 'Admin Klinik');
    $designation = rbrcDesignate($fx, $designee);
    $approver = userInRole('Super Admin');

    $handle = rbrcCompeting([
        rbrcLockStep($fx['origin']),
        ['kind' => 'approve_branch_change', 'request' => $requestId, 'actor' => (int) $approver->id],
    ]);

    test()->actingAs($designee);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $designee));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('DENIED:'.RmeBillingDisputeDenied::BRANCH_OUT_OF_SCOPE)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        // The designation names the branch, not the person: still active for
        // its branch, usable again only if the account works there again.
        ->and($designation->fresh()->isActive())->toBeTrue();
});

// ─── D: duplicate designation ───────────────────────────────────────────────

it('D: two racing designations of one account for one branch store one; the loser is told it is a duplicate', function () {
    $fx = rbrcFixture();
    $handle = rbrcCompeting([rbrcDesignateStep($fx, $fx['designee'])]);

    test()->actingAs($fx['governor']);
    [$outcome, $waited] = rbrcRace(fn () => rbrcDesignate($fx, $fx['designee']));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toStartWith('REFUSED:')
        ->and($outcome)->toContain('BILLING_DISPUTE_REVIEWER_DUPLICATE')
        ->and(RmeBillingDisputeReviewer::query()
            ->where('user_id', $fx['designee']->id)->where('branch_id', $fx['branch']->id)
            ->whereNull('revoked_at')->count())->toBe(1);
});

// ─── E: dispute resolution vs Kontrol-only payment ──────────────────────────

it('E: a Kontrol-only payment waiting on the origin dispute resolution that commits is refused — the origin is collectable again', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $before = rbrcOriginMoney($fx['origin']);
    $handle = rbrcCompeting([[
        'kind' => 'resolve_dispute', 'dispute' => (int) $dispute->id, 'actor' => (int) $fx['reviewer']->id,
        'data' => rbrcConfirmData(),
    ]]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and(rbrcPayments($fx['control']))->toBe(0)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before);
});

it('E2: a Kontrol-only payment waiting on a resolution that rolls back is accepted and allocates nothing to the origin', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    $before = rbrcOriginMoney($fx['origin']);
    $handle = rbrcCompeting([[
        'kind' => 'resolve_dispute', 'dispute' => (int) $dispute->id, 'actor' => (int) $fx['reviewer']->id,
        'data' => rbrcConfirmData(),
    ]], 'rollback');

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbrcPayments($fx['control']))->toBe(1)
        ->and($fx['control']->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before);
});

// ─── F: origin dispute opened vs Kontrol-only payment ───────────────────────

it('F: a Kontrol-only payment waiting on an origin report that commits is accepted, origin untouched', function () {
    $fx = rbrcFixture();
    $before = rbrcOriginMoney($fx['origin']);
    $handle = rbrcCompeting([rbrcReportStep($fx['origin'], $fx['cashier'])]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and(rbrcPayments($fx['control']))->toBe(1)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before)
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['origin']->id)->value('status'))->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(DB::table('sys_audit_logs')->where('action', RmeFinancialAuditEvent::ACTION_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED)->count())->toBe(1);
});

it('F2: a Kontrol-only payment waiting on an origin report that rolls back is refused — the origin is not disputed', function () {
    $fx = rbrcFixture();
    $before = rbrcOriginMoney($fx['origin']);
    $handle = rbrcCompeting([rbrcReportStep($fx['origin'], $fx['cashier'])], 'rollback');

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED')
        ->and(rbrcPayments($fx['control']))->toBe(0)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before);
});

// ─── G: Kontrol B dispute opened vs B payment ───────────────────────────────

it('G: a Kontrol-only payment waiting on a dispute of the Kontrol invoice itself is held', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([rbrcReportStep($fx['control'], $fx['cashier'])]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rbrcPayments($fx['control']))->toBe(0)
        ->and(rbrcPayments($fx['origin']))->toBe(0);
});

// ─── H: Kontrol B correction opened vs B payment ────────────────────────────

it('H: a Kontrol-only payment waiting on a correction submitted on the Kontrol invoice is held by D10', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([[
        'kind' => 'submit_correction', 'invoice' => (int) $fx['control']->id, 'actor' => (int) $fx['maker']->id,
        'data' => rbrcCorrectionData($fx['control']),
    ]]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('koreksi')
        ->and(rbrcPayments($fx['control']))->toBe(0)
        ->and($fx['control']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── I: combined A+B payment vs dispute activation ──────────────────────────

it('I: the ordinary combined payment waiting on an origin report that commits is refused whole — no partial allocation', function () {
    $fx = rbrcFixture();
    $handle = rbrcCompeting([rbrcReportStep($fx['origin'], $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $form = rmeDeclaredPaymentForm($fx['control'], [
        'amount' => 1000000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$fx['origin']->id => 0, $fx['control']->id => 0],
    ]);
    [$outcome, $waited] = rbrcRace(fn () => app(RmePaymentService::class)->allocateControlPayment($fx['control']->fresh(), $fx['cashier'], $form));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rbrcPayments($fx['origin']))->toBe(0)
        ->and(rbrcPayments($fx['control']))->toBe(0);
});

// ─── J: two concurrent B-only payments ──────────────────────────────────────

it('J: two concurrent full Kontrol-only payments record exactly one; the second sees the paid invoice', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $before = rbrcOriginMoney($fx['origin']);
    $second = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $handle = rbrcCompeting([[
        'kind' => 'pay_control_only', 'invoice' => (int) $fx['control']->id, 'actor' => (int) $fx['cashier']->id,
        'form' => rbrcPayForm($fx['control'], 500000),
    ]]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx, 500000, $second));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toStartWith('REFUSED:')
        ->and(rbrcPayments($fx['control']))->toBe(1)
        ->and((float) DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['control']->id)->sum('amount'))->toEqual(500000.0)
        ->and($fx['control']->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before);
});

// ─── K: payment retry vs payment commit ─────────────────────────────────────

it('K: a retry waiting on a first attempt that rolls back records exactly one payment', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([[
        'kind' => 'pay_control_only', 'invoice' => (int) $fx['control']->id, 'actor' => (int) $fx['cashier']->id,
        'form' => rbrcPayForm($fx['control'], 500000),
    ]], 'rollback');

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and(rbrcPayments($fx['control']))->toBe(1)
        ->and($fx['control']->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('K2: a full-amount retry waiting on a first attempt that commits is refused — never an overpayment', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $handle = rbrcCompeting([[
        'kind' => 'pay_control_only', 'invoice' => (int) $fx['control']->id, 'actor' => (int) $fx['cashier']->id,
        'form' => rbrcPayForm($fx['control'], 500000),
    ]]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toStartWith('REFUSED:')
        ->and(rbrcPayments($fx['control']))->toBe(1)
        ->and((float) DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['control']->id)->sum('amount'))->toEqual(500000.0);
});

// ─── L: origin version change vs separate Kontrol payment ───────────────────

it('L: a Kontrol-only payment waiting on an origin correction that applies settles the Kontrol invoice only, unbound to the origin version', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    test()->actingAs($fx['maker']);
    $correction = app(RmeInvoiceCorrectionService::class)->submit($fx['origin']->fresh(), $fx['maker'], rbrcCorrectionData($fx['origin']));
    $handle = rbrcCompeting([[
        'kind' => 'approve_correction', 'correction' => (int) $correction->id, 'actor' => (int) $fx['checker']->id,
    ]]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx));
    rbrcJoin($handle);

    $origin = rbrcOriginMoney($fx['origin']);
    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('OK')
        ->and(rbrcPayments($fx['control']))->toBe(1)
        // The correction applied on its own terms; the Kontrol payment moved none of it.
        ->and($origin['version'])->toBe(1)
        ->and($origin['grand_total'])->toEqual(350000.0)
        ->and($origin['remaining'])->toEqual(350000.0)
        ->and($origin['payments'])->toBe(0)
        ->and(RmeInvoiceCorrection::query()->findOrFail($correction->id)->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});

// ─── M: account deactivation vs review ──────────────────────────────────────

it('M: a deactivation of the designated reviewer committed while the review waits refuses the review', function () {
    $fx = rbrcFixture();
    $dispute = rbrcReport($fx, $fx['origin']);
    rbrcDesignate($fx, $fx['designee']);
    $designee = $fx['designee']; // loaded BEFORE the deactivation: still "active" in memory
    $handle = rbrcCompeting([
        rbrcLockStep($fx['origin']),
        ['kind' => 'deactivate_user', 'user' => (int) $designee->id],
    ]);

    test()->actingAs($designee);
    [$outcome, $waited] = rbrcRace(fn () => app(RmeBillingDisputeService::class)->startReview($dispute, $designee));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toBe('DENIED:'.RmeBillingDisputeDenied::ACTOR_UNUSABLE)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

// ─── N: branch reassignment vs payment ──────────────────────────────────────

it('N: a cashier whose branch reassignment commits while the Kontrol-only payment waits is refused', function () {
    $fx = rbrcFixture();
    rbrcReport($fx, $fx['origin']);
    $before = rbrcOriginMoney($fx['origin']);
    [$kasir, $requestId] = rbrcContextBoundWithPendingTransfer($fx, 'Kasir');
    $approver = userInRole('Super Admin');

    $handle = rbrcCompeting([
        rbrcLockStep($fx['control']),
        ['kind' => 'approve_branch_change', 'request' => $requestId, 'actor' => (int) $approver->id],
    ]);

    [$outcome, $waited] = rbrcRace(fn () => rbrcPayOnly($fx, 500000, $kasir));
    rbrcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($outcome)->toContain('cabang lain')
        ->and(rbrcPayments($fx['control']))->toBe(0)
        ->and(rbrcOriginMoney($fx['origin']))->toBe($before);
});
