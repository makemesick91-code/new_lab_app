<?php

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the operator can actually find
 * the patient, and is told when they cannot.
 *
 * THE PRODUCTION DEFECT. The upload page asked for `patient_id`: the internal
 * surrogate key of `mst_patients`. That number is displayed nowhere in
 * DaengtisiaMS — every other patient-selection surface in the product uses the
 * canonical Nomor RM — so an operator typed the identifier they actually hold,
 * `Request::integer()` coerced it to 0, and the page silently re-rendered the
 * SAME "Belum ada pasien dipilih" panel it shows before any input at all. HTTP
 * 200, nothing logged, no signal of any kind.
 *
 * WHAT THIS FILE PINS, and why each case is here rather than assumed:
 *   - the canonical Nomor RM resolves (the reported defect);
 *   - FOUND / NOT_FOUND / RUNTIME_ERROR are three DISTINCT rendered states,
 *     never one shared blank panel;
 *   - a malformed identifier can never coerce into a DIFFERENT patient — before
 *     this sprint `?patient_id=1abc` and `?patient_id[]=x` both silently
 *     resolved patient #1, which in a clinical-evidence workflow is a
 *     wrong-patient hazard;
 *   - finding a patient is NOT authorization to upload against them;
 *   - the lookup discloses identity only — never KTP/NIK, phone, address or
 *     any clinical detail;
 *   - none of the archive's existing invariants moved.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Requests\LookupLegacyOdontogramPatientRequest;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Middleware\PermissionMiddleware;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/** The upload page, as the operator's browser requests it. */
function lodoLookup(array $query = [])
{
    return test()->get(route('settings.rme.legacy-odontograms.create', $query));
}

/*
|--------------------------------------------------------------------------
| FOUND — the reported defect
|--------------------------------------------------------------------------
*/

it('resolves the canonical Nomor RM the operator actually holds', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Pasien ditemukan')
        ->assertSee('Pasien Arsip Lama')
        ->assertSee($patient->medical_record_number)
        ->assertDontSee('Belum ada pasien dipilih');
});

it('still resolves an explicit patient_id, so existing links keep working', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['patient_id' => $patient->getKey()]))
        ->assertOk()
        ->assertSee('Pasien ditemukan')
        ->assertSee('Pasien Arsip Lama')
        ->assertDontSee('Belum ada pasien dipilih');
});

it('shows the derived branch and the native odontogram cutoff once a patient is found', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Cabang Telkomas')
        ->assertSee('10-03-2022');
});

/*
|--------------------------------------------------------------------------
| NOT_FOUND — distinct from "nothing entered yet"
|--------------------------------------------------------------------------
*/

it('reports an unknown Nomor RM as not found rather than as a blank page', function () {
    lodoPatient();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => 'DG-TKM1-2024-9999']))
        ->assertOk()
        ->assertSee('Pasien tidak ditemukan')
        ->assertDontSee('Belum ada pasien dipilih');
});

it('reports an unknown patient_id as not found', function () {
    lodoPatient();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['patient_id' => 999999]))
        ->assertOk()
        ->assertSee('Pasien tidak ditemukan');
});

it('treats a soft-deleted patient as not found', function () {
    $gone = lodoPatient(['name' => 'Pasien Dihapus']);
    $rm = $gone->medical_record_number;
    $id = $gone->getKey();
    $gone->delete();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $rm]))
        ->assertOk()
        ->assertSee('Pasien tidak ditemukan')
        ->assertDontSee('Pasien Dihapus');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['patient_id' => $id]))
        ->assertOk()
        ->assertSee('Pasien tidak ditemukan');
});

it('keeps the neutral empty state when nothing has been entered yet', function () {
    lodoPatient();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create'))
        ->assertOk()
        ->assertSee('Belum ada pasien dipilih')
        ->assertDontSee('Pasien tidak ditemukan')
        ->assertDontSee('Gagal mengambil data pasien');
});

/*
|--------------------------------------------------------------------------
| RUNTIME_ERROR — a third state, never silently blank
|--------------------------------------------------------------------------
*/

it('renders a runtime error state when the lookup itself fails', function () {
    $patient = lodoPatient();

    $this->mock(LegacyOdontogramPatientRepositoryInterface::class, function ($mock) {
        $mock->shouldReceive('findSelectableById')->andThrow(new RuntimeException('database is down'));
        $mock->shouldReceive('searchByMedicalRecordNumber')->andThrow(new RuntimeException('database is down'));
    });

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Gagal mengambil data pasien. Silakan coba lagi.')
        ->assertDontSee('Pasien tidak ditemukan')
        ->assertDontSee('Belum ada pasien dipilih');
});

/*
|--------------------------------------------------------------------------
| Malformed identifiers — never a 500, never a DIFFERENT patient
|--------------------------------------------------------------------------
*/

it('never coerces a malformed identifier into a different patient', function (string $case) {
    $decoy = lodoPatient(['name' => 'Pasien Pertama']);
    lodoNativeOdontogram($decoy, '2022-03-10');

    /*
     * The historical hazard, stated portably. `intval()` never fails, it
     * guesses: `intval('7abc')` is 7, and `intval(['x'])` is 1 whatever the
     * array holds. So a mistyped or malformed identifier used to resolve a
     * REAL, DIFFERENT patient and present them as the operator's choice.
     *
     * The cases are built from the decoy's OWN id here rather than from a
     * hard-coded 1: PostgreSQL does not restart its sequences between tests, so
     * "the first patient is id 1" is an SQLite-only fact, and pinning it would
     * make this suite green on the wrong driver.
     */
    $query = match ($case) {
        'numeric prefix garbage' => ['patient_id' => $decoy->getKey().'abc'],
        'array payload' => ['patient_id' => [(string) $decoy->getKey()]],
        'array rm payload' => ['rm' => [(string) $decoy->medical_record_number]],
        'whitespace' => ['patient_id' => '   '],
        'negative' => ['patient_id' => -$decoy->getKey()],
        'zero' => ['patient_id' => 0],
        'huge integer' => ['patient_id' => '99999999999999999999'],
        'non numeric' => ['patient_id' => 'abc'],
        'sql-ish rm' => ['rm' => "' OR 1=1 --"],
        'like wildcards' => ['rm' => '%%%%'],
    };

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', $query))
        ->assertOk()
        ->assertDontSee('Pasien Pertama')
        ->assertDontSee('Pasien ditemukan');
})->with([
    'numeric prefix garbage',
    'array payload',
    'array rm payload',
    'whitespace',
    'negative',
    'zero',
    'huge integer',
    'non numeric',
    'sql-ish rm',
    'like wildcards',
]);

/*
|--------------------------------------------------------------------------
| Least disclosure
|--------------------------------------------------------------------------
*/

it('discloses identity only — never KTP/NIK, phone, address or clinical detail', function () {
    $patient = lodoPatient([
        'name' => 'Pasien Arsip Lama',
        'ktp_number' => '7371010101900001',
        'phone' => '081234567890',
        'whatsapp_number' => '081234567891',
        'address' => 'Jalan Rahasia Nomor 7',
        'date_of_birth' => '1990-01-01',
    ]);
    lodoNativeOdontogram($patient, '2022-03-10');

    $html = (string) $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->getContent();

    expect($html)
        ->not->toContain('7371010101900001')
        ->not->toContain('081234567890')
        ->not->toContain('081234567891')
        ->not->toContain('Jalan Rahasia Nomor 7')
        ->and($html)->toContain('Pasien Arsip Lama');
});

/*
|--------------------------------------------------------------------------
| Authorization — the lookup is not a public door
|--------------------------------------------------------------------------
*/

it('refuses the lookup to an operator holding only the read permission', function () {
    $patient = lodoPatient();

    $this->actingAs(userWith(['view_legacy_odontogram_imports']))
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertForbidden();
});

it('refuses the lookup to an operator with no legacy odontogram permission at all', function () {
    $patient = lodoPatient();

    $this->actingAs(userWith([]))
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertForbidden();
});

it('hides the whole lookup when the migration capability is off', function () {
    $patient = lodoPatient();
    lodoFlag(false);

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Branch — identity is global, the ARCHIVE is not
|--------------------------------------------------------------------------
*/

it('ignores a branch_id submitted with the lookup', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $foreign = lodoBranch('LDK2', 'Cabang Landak');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', [
            'rm' => $patient->medical_record_number,
            'branch_id' => $foreign->getKey(),
            'origin_branch_id' => $foreign->getKey(),
        ]))
        ->assertOk()
        // The branch still comes from the patient's own Nomor RM, never the request.
        ->assertSee('Cabang Telkomas')
        ->assertDontSee('Cabang Landak');
});

/*
|--------------------------------------------------------------------------
| Finding a patient is NOT authorization to upload
|--------------------------------------------------------------------------
*/

it('re-resolves and re-validates patient_id on submit rather than trusting the browser', function () {
    $shown = lodoPatient(['name' => 'Pasien Ditampilkan']);
    lodoNativeOdontogram($shown, '2022-03-10');

    // Looked one patient up...
    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $shown->medical_record_number]))
        ->assertOk();

    // ...then submitted a different, non-existent one.
    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => 999999,
            'selected_odontogram_date' => '2019-06-01',
            'document' => lodoPdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertSessionHasErrors('patient_id');

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('refuses a malformed patient_id at submit instead of coercing it', function (string $case) {
    $decoy = lodoPatient();
    lodoNativeOdontogram($decoy, '2022-03-10');

    $patientId = match ($case) {
        // Would have coerced to the decoy's own id under the old reader.
        'numeric prefix garbage' => $decoy->getKey().'abc',
        'array payload' => [(string) $decoy->getKey()],
        'non numeric' => 'abc',
        'unknown id' => $decoy->getKey() + 100000,
    };

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patientId,
            'selected_odontogram_date' => '2019-06-01',
            'document' => lodoPdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertSessionHasErrors('patient_id');

    expect(LegacyOdontogramImport::count())->toBe(0);
})->with([
    'numeric prefix garbage',
    'array payload',
    'non numeric',
    'unknown id',
]);

/*
|--------------------------------------------------------------------------
| Nothing else moved
|--------------------------------------------------------------------------
*/

it('creates no native clinical row while looking a patient up', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $visits = ClinicVisit::count();
    $odontograms = Odontogram::count();
    $imports = LegacyOdontogramImport::count();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk();

    expect(ClinicVisit::count())->toBe($visits)
        ->and(Odontogram::count())->toBe($odontograms)
        ->and(LegacyOdontogramImport::count())->toBe($imports);
});

it('does not treat an empty placeholder odontogram as the native cutoff', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Pasien ditemukan')
        // A contentless placeholder is not clinical evidence, so it must not
        // become a date ceiling.
        ->assertSee('Belum ada')
        ->assertDontSee('10-03-2022');
});

it('clears the patient panel again when the identifier is removed', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Pasien Arsip Lama');

    // The page is re-rendered from the query string on every submit, so an
    // emptied field cannot leave a stale identity on screen.
    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => '']))
        ->assertOk()
        ->assertDontSee('Pasien Arsip Lama')
        ->assertSee('Belum ada pasien dipilih');
});

it('leaves the patient master data untouched', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    $before = Patient::query()->findOrFail($patient->getKey())->toArray();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk();

    expect(Patient::query()->findOrFail($patient->getKey())->toArray())->toEqual($before);
});

/*
|--------------------------------------------------------------------------
| Regressions found by adversarial review of this sprint's own diff
|--------------------------------------------------------------------------
*/

it('still refuses an archive dated before the patient was born, through the real HTTP path', function () {
    /*
     * Routing `store()` through a COLUMN-PROJECTED repository nearly deleted
     * this guard in silence. `LegacyOdontogramDateRuleService` reads
     * `$patient->date_of_birth`; Eloquent returns null for an unselected
     * attribute instead of throwing, and the rule skips a null birth date by
     * design — so a projection that omitted the column would have let a chart
     * dated before the patient existed become permanent clinical evidence, with
     * no error and no log line.
     *
     * The existing date-rule tests hand the SERVICE a fully hydrated factory
     * model, so they cannot see this. This one goes through the controller.
     */
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->getKey(),
            'selected_odontogram_date' => '1985-03-12',
            'document' => lodoPdfUpload(),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertSessionHasErrors('selected_odontogram_date');

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('keeps the searched Nomor RM out of the log when the lookup fails', function () {
    $rm = 'DG-TKM1-2024-4242';

    /*
     * `QueryException::getMessage()` INTERPOLATES the bindings into the SQL it
     * reports, so logging it raw writes the operator's searched Nomor RM into
     * laravel.log on exactly the faults the error state exists to handle.
     */
    $exception = new QueryException(
        'pgsql',
        'select * from "mst_patients" where "medical_record_number" = ?',
        [$rm],
        new RuntimeException('SQLSTATE[08006] connection failure'),
    );

    expect($exception->getMessage())->toContain($rm);

    $this->mock(LegacyOdontogramPatientRepositoryInterface::class, function ($mock) use ($exception) {
        $mock->shouldReceive('searchByMedicalRecordNumber')->andThrow($exception);
        $mock->shouldReceive('findSelectableById')->andThrow($exception);
    });

    Log::spy();

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $rm]))
        ->assertOk()
        ->assertSee('Gagal mengambil data pasien. Silakan coba lagi.');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($rm): bool {
        expect(json_encode($context))->not->toContain($rm);
        expect($context)->toHaveKey('reason');

        return true;
    });
});

it('labels the field with the resolved patient Nomor RM, never a stranger', function () {
    $shown = lodoPatient(['name' => 'Pasien Terpilih']);
    lodoNativeOdontogram($shown, '2022-03-10');

    $other = lodoPatient(['name' => 'Pasien Lain']);

    // A hand-edited URL naming one patient by id and another by RM. The id wins
    // the lookup, so the field must not go on displaying the other patient's
    // identifier above the resolved patient's card.
    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', [
            'patient_id' => $shown->getKey(),
            'rm' => $other->medical_record_number,
        ]))
        ->assertOk()
        ->assertSee('Pasien Terpilih')
        ->assertSee('value="'.$shown->medical_record_number.'"', false)
        ->assertDontSee('value="'.$other->medical_record_number.'"', false);
});

/*
|--------------------------------------------------------------------------
| Coverage gaps found by mutation testing this sprint's own suite
|--------------------------------------------------------------------------
*/

it('renders a lookup form bound to the exact parameter the server reads', function () {
    $patient = lodoPatient(['name' => 'Pasien Arsip Lama']);
    lodoNativeOdontogram($patient, '2022-03-10');

    /*
     * Mutation testing caught this gap: renaming the rendered input from `rm`
     * to anything else left every HTTP test in this file green, because they
     * all pass `rm` in the query string and never touch the form. That is
     * EXACTLY the original defect — the operator types, the value never
     * arrives, and the page looks like it was never used. The browser test
     * catches it, but a browser is too slow a feedback loop for a binding this
     * load-bearing, so it is pinned here too.
     */
    $html = (string) $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create'))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('name="rm"')
        ->toContain('action="'.route('settings.rme.legacy-odontograms.create').'"');

    // ...and that same parameter really does drive the lookup.
    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertOk()
        ->assertSee('Pasien ditemukan');
});

it('keeps the lookup reachable at its published URL', function () {
    /*
     * Also from mutation testing. The sibling assertion in
     * LegacyOdontogramFoundationTest uses `toContain`, so a path renamed to
     * `.../create-broken` still contains `.../create` and passes — every link
     * in the app is generated by route NAME, so nothing else notices either.
     * Only an operator's existing bookmark breaks. Pinned exactly, not by
     * prefix.
     */
    expect(route('settings.rme.legacy-odontograms.create'))
        ->toEndWith('/settings/rme/legacy-odontograms/create');

    $this->actingAs(lodoOperator())
        ->get('/settings/rme/legacy-odontograms/create')
        ->assertOk();
});

it('still refuses an unauthorized operator when the route middleware is lifted', function () {
    /*
     * Also from mutation testing: deleting the controller's own
     * `authorize('create', …)` left all 35 tests green, because the route's
     * `permission:create_legacy_odontogram_imports` middleware was answering
     * first and the two gates check the SAME permission.
     *
     * That redundancy is deliberate — the controller docblock promises three
     * independent gates — but an untested backstop is indistinguishable from a
     * missing one. Lifting the middleware proves the second gate is really
     * there rather than merely written down.
     */
    $patient = lodoPatient();

    $this->withoutMiddleware(PermissionMiddleware::class);

    $this->actingAs(userWith(['view_legacy_odontogram_imports']))
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertForbidden();
});

it('still answers 404 for a disabled capability when the route middleware is lifted', function () {
    // The capability check must stay AHEAD of the policy: a disabled surface
    // answers 404 and reveals nothing, never a 403 that would confirm it exists.
    $patient = lodoPatient();
    lodoFlag(false);

    $this->withoutMiddleware(PermissionMiddleware::class);

    $this->actingAs(userWith([]))
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $patient->medical_record_number]))
        ->assertNotFound();
});

it('derives the identifier from the request alone, never from server-side state', function () {
    /*
     * Mutation testing found a blind spot this closes. A defect that remembers
     * the last-searched Nomor RM in the session — so an emptied field silently
     * resurrects the previous patient — is invisible to every HTTP test in this
     * file, because Laravel's test client does not carry the session cookie
     * between requests, and invisible to the browser suite too. On a screen that
     * writes permanent clinical evidence, showing one patient's card while the
     * field is empty is precisely the wrong-patient hazard this sprint exists
     * to remove.
     *
     * Asserted where the behaviour actually IS observable: the request object
     * itself. An empty identifier must normalise to null even when the session
     * is primed.
     */
    session(['lodo_last_rm' => 'DG-TKM1-2024-0001']);
    session(['rm' => 'DG-TKM1-2024-0001']);

    $request = LookupLegacyOdontogramPatientRequest::create('/settings/rme/legacy-odontograms/create', 'GET', ['rm' => '']);
    $request->setContainer(app());
    $request->validateResolved();

    expect($request->medicalRecordNumber())->toBeNull()
        ->and($request->patientId())->toBeNull()
        ->and($request->identifierSupplied())->toBeFalse();
});
