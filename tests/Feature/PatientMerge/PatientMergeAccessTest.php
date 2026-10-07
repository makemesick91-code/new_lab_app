<?php

use App\Models\User;
use App\Modules\PatientMerge\Models\PatientMergeCase;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

/*
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — the server-side boundary.
 * The sidebar is a courtesy; these assert the routes, the policy, branch
 * scope, maker-checker and that no full NIK ever reaches a page.
 */

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    seedAccessControl();
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

$pages = [
    'patient-merge.dashboard',
    'patient-merge.candidates.index',
    'patient-merge.cases.index',
    'patient-merge.history.index',
    'patient-merge.aliases.index',
];

it('redirects guests to login on every page', function () use ($pages): void {
    foreach (array_merge($pages, ['patient-merge.manual.create', 'patient-merge.review.index']) as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

it('denies roles that hold no duplicate-resolution permission', function (string $role) use ($pages): void {
    $user = pmUser($role);

    foreach (array_merge($pages, ['patient-merge.manual.create', 'patient-merge.review.index']) as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }

    $this->actingAs($user)->post(route('patient-merge.cases.store'), [])->assertForbidden();
})->with(['Owner', 'Doctor', 'Kasir', 'Perawat', 'Admin Lab']);

it('lets a requester role open the request surface but not the review queue', function (string $role): void {
    $branch = pmBranch();
    $user = pmUser($role);
    $user->forceFill(['branch_id' => $branch->id])->save();

    $this->actingAs($user)->get(route('patient-merge.dashboard'))->assertOk()->assertSee('Dashboard Duplikasi Pasien');
    $this->actingAs($user)->get(route('patient-merge.manual.create'))->assertOk();
    $this->actingAs($user)->get(route('patient-merge.review.index'))->assertForbidden();
})->with(['Admin Klinik', 'Front Office']);

it('renders every page for a reviewer', function () use ($pages): void {
    $user = pmUser('Supervisor RME');

    foreach (array_merge($pages, ['patient-merge.manual.create', 'patient-merge.review.index']) as $route) {
        $this->actingAs($user)->get(route($route))->assertOk();
    }
});

it('creates a case over HTTP and never renders a full NIK on the case page', function (): void {
    $requester = pmUser('Supervisor RME');
    $a = pmPatient(['name' => 'Rahma Dewi', 'ktp_number' => '7371000000001234', 'phone' => '081299990001']);
    $b = pmPatient(['name' => 'Rahma Dewi', 'ktp_number' => '7371000000005678', 'phone' => '081299990001']);

    $response = $this->actingAs($requester)->post(route('patient-merge.cases.store'), [
        'patient_a_id' => $a->id,
        'patient_b_id' => $b->id,
        'request_reason' => 'Nama dan nomor HP sama, diduga daftar dua kali.',
    ]);

    $case = PatientMergeCase::query()->firstOrFail();
    $response->assertRedirect(route('patient-merge.cases.show', $case));

    $page = $this->actingAs($requester)->get(route('patient-merge.cases.show', $case))->assertOk();
    $page->assertSee('************1234')->assertDontSee('7371000000001234')->assertDontSee('7371000000005678')
        ->assertDontSee('081299990001');
});

it('rejects the same patient as both A and B at the request boundary', function (): void {
    $requester = pmUser('Supervisor RME');
    $a = pmPatient();

    $this->actingAs($requester)->post(route('patient-merge.cases.store'), [
        'patient_a_id' => $a->id, 'patient_b_id' => $a->id, 'request_reason' => 'Alasan yang cukup panjang.',
    ])->assertSessionHasErrors('patient_b_id');

    expect(PatientMergeCase::query()->count())->toBe(0);
});

it('keeps a branch-pinned operator inside their branch (out-of-scope reads as not found)', function (): void {
    $own = pmBranch('LDK2', 'Cabang Landak');
    $other = pmBranch('ATG3', 'Cabang Antang');
    $operator = pmUser('Admin Klinik');
    rmeMakeAdminClinicActive($operator, $own);

    $mine = pmPatient([], $own);
    $theirs = pmPatient([], $other);

    $this->actingAs($operator)->post(route('patient-merge.cases.store'), [
        'patient_a_id' => $mine->id, 'patient_b_id' => $theirs->id, 'request_reason' => 'Alasan yang cukup panjang.',
    ])->assertSessionHasErrors();

    expect(PatientMergeCase::query()->count())->toBe(0);

    // A case created by a reviewer on another branch is invisible to the operator.
    $reviewer = pmUser('Supervisor RME');
    $case = pmReadyCase(pmPatient([], $other), pmPatient([], $other), $reviewer);
    $this->actingAs($operator)->get(route('patient-merge.cases.show', $case))->assertForbidden();
});

it('requires the privileged reviewer permission for a cross-branch case', function (): void {
    $ldk = pmBranch('LDK2', 'Cabang Landak');
    $atg = pmBranch('ATG3', 'Cabang Antang');
    $a = pmPatient([], $ldk);
    $b = pmPatient([], $atg);

    $reviewer = pmUser('Supervisor RME');
    $this->actingAs($reviewer)->post(route('patient-merge.cases.store'), [
        'patient_a_id' => $a->id, 'patient_b_id' => $b->id, 'request_reason' => 'Pasien pindah cabang dan terdaftar ulang.',
    ])->assertSessionHasNoErrors();

    $case = PatientMergeCase::query()->firstOrFail();
    expect($case->cross_branch)->toBeTrue()
        ->and($case->risk_flags)->toContain('cross_branch');
});

it('lets only a reviewer approve, and never the requester', function (): void {
    $requester = pmUser('Admin Klinik');
    $branch = pmBranch();
    rmeMakeAdminClinicActive($requester, $branch);
    $case = pmSubmittedCase(pmPatient([], $branch), pmPatient([], $branch), $requester);

    $this->actingAs($requester)->post(route('patient-merge.cases.approve', $case), ['confirm_merge' => '1'])->assertForbidden();

    $reviewer = pmUser('Supervisor RME');
    $this->actingAs($reviewer)->post(route('patient-merge.cases.approve', $case), [])->assertSessionHasErrors('confirm_merge');
    $this->actingAs($reviewer)->post(route('patient-merge.cases.approve', $case), ['confirm_merge' => '1'])->assertRedirect();

    expect($case->fresh()->status)->toBe(PatientMergeStatus::COMPLETED);
});

it('addresses cases by UUID, never by sequential id', function (): void {
    $reviewer = pmUser('Supervisor RME');
    $case = pmReadyCase(pmPatient(), pmPatient(), $reviewer);

    $this->actingAs($reviewer)->get('/rme/patient-merge/cases/'.$case->id)->assertNotFound();
    $this->actingAs($reviewer)->get('/rme/patient-merge/cases/'.$case->uuid)->assertOk();
});

it('does not let a requester edit a case that is no longer a draft', function (): void {
    $requester = pmUser('Supervisor RME');
    $case = pmSubmittedCase(pmPatient(), pmPatient(), $requester);

    $this->actingAs($requester)->put(route('patient-merge.cases.resolve', $case), ['canonical_patient_id' => $case->patient_b_id])->assertForbidden();
});

it('shows the dedicated sidebar parent only to authorized users', function (): void {
    $reviewer = pmUser('Supervisor RME');
    $this->actingAs($reviewer)->get(route('patient-merge.dashboard'))
        ->assertSee('Duplikasi Pasien')->assertSee('Review &amp; Approval', false)->assertSee('RM Alias');

    /** @var User $kasir */
    $kasir = pmUser('Kasir');
    $kasir->forceFill(['branch_id' => pmBranch()->id])->save();
    $this->actingAs($kasir)->get(route('dashboard'))->assertDontSee('Pilih Pasien Manual');
});
