<?php

/**
 * BUGFIX-NEW-VISIT-PATIENT-SEARCH-RUNTIME-1
 *
 * The "Kunjungan Baru" combobox shipped correct authorization, correct scoping
 * and a correct response contract — and still returned HTTP 500 for every
 * search an operator typed in production.
 *
 * The query carried its LIKE escape character as a BACKSLASH:
 *
 *     LOWER(name) LIKE LOWER(?) ESCAPE '\'
 *
 * PDO's SQL parser must rewrite `?` into `$1` before pdo_pgsql can send a
 * statement, and up to and including PHP 8.3 that parser treats a backslash
 * inside a single-quoted literal as escaping the closing quote. The literal
 * therefore never terminates where SQL says it does, the placeholders that
 * follow are swallowed into it, and PDO ends up seeing fewer `?` than the
 * bindings Laravel hands it:
 *
 *     SQLSTATE[HY093]: Invalid parameter number: parameter was not defined
 *
 * Nothing about that is visible to the driver the suite ran on. pdo_sqlite
 * accepts positional `?` natively, so PDO never runs the rewriting parser and
 * the malformed literal is never tokenised at all. The combination that fails
 * is exactly one: PostgreSQL, on PHP <= 8.3 — which is precisely what serves
 * production, and precisely what no gate had ever pointed at this code.
 *
 * So these tests are deliberately split in two:
 *
 *  1. `pins the mechanism` reproduces the parser fault ITSELF, in PHP, against
 *     the SQL the repository actually compiles. It fails on every driver and
 *     every PHP version, so the defect can never again hide behind whichever
 *     database the suite happens to be pointed at.
 *
 *  2. The runtime tests execute the real endpoint on the CONFIGURED connection
 *     — PostgreSQL in the critical gate — and pin the three states that must
 *     stay distinct: results, a successful empty result, and a genuine error.
 *     Production collapsed the middle state into the last one.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-08-29 09:00:00'));

    $this->clinic = Clinic::factory()->create();

    $this->ldk2 = Branch::factory()->create([
        'code' => 'LDK2', 'name' => 'Cabang Landak', 'is_rme_enabled' => true, 'is_active' => true,
    ]);

    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    rmeMakeDoctorOnline($this->doctor, $this->ldk2);

    $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);

    $this->jefri = Patient::factory()->create([
        'name' => 'Jefri Salim',
        'medical_record_number' => 'DG-LDK2-2026-0451',
        'branch_id' => $this->ldk2->id,
        'phone' => '081234500000',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Count the `?` placeholders PDO's own SQL parser finds, using the
 * backslash-escape rule that ext/pdo applies to single-quoted literals up to
 * and including PHP 8.3.
 *
 * This is the tokeniser, not an approximation of it: pdo_pgsql rewrites `?`
 * into `$n` through exactly this scan, so a statement whose count here differs
 * from its binding count is the HY093 that reached production. Reproducing the
 * rule in PHP is what makes the guard driver-independent — it holds on SQLite,
 * where the real parser never runs, and on PHP 8.5, where it was fixed.
 */
function pdoLegacyPlaceholderCount(string $sql): int
{
    $count = 0;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        if ($char === "'") {
            // Single-quoted literal. A backslash escapes the NEXT character,
            // so `'\'` does not close here — this is the whole defect.
            for ($i++; $i < $length; $i++) {
                if ($sql[$i] === '\\') {
                    $i++;

                    continue;
                }

                if ($sql[$i] === "'") {
                    break;
                }
            }

            continue;
        }

        if ($char === '"') {
            // Quoted identifier: no backslash escaping, runs to the next quote.
            for ($i++; $i < $length && $sql[$i] !== '"'; $i++);

            continue;
        }

        if ($char === '?') {
            $count++;
        }
    }

    return $count;
}

/**
 * Capture the statement the repository actually hands to PDO.
 *
 * `DB::listen()` reports `sql` with its placeholders still in place, which is
 * the string ext/pdo parses. `DB::pretend()` deliberately cannot be used here:
 * it substitutes the bindings into the SQL before logging it, so the very thing
 * this guard measures — the relationship between placeholders and bindings —
 * has already been erased by the time it could be read.
 *
 * @return array{query: string, bindings: array<int, mixed>}
 */
function capturedPatientSearchStatement(array $branchIds, string $term): array
{
    /** @var PatientRepositoryInterface $repository */
    $repository = app(PatientRepositoryInterface::class);

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'mst_patients')) {
            $statements[] = ['query' => $query->sql, 'bindings' => $query->bindings];
        }
    });

    $repository->searchSelectable($branchIds, $term, PatientSelectorSearchService::RESULT_LIMIT);

    expect($statements)->not->toBeEmpty();

    return $statements[0];
}

// ---------------------------------------------------------------------------
// 1. The mechanism — driver-independent, so it cannot hide again
// ---------------------------------------------------------------------------

it('pins the mechanism: PDO sees exactly as many placeholders as there are bindings', function () {
    $statement = capturedPatientSearchStatement([$this->ldk2->id], 'Jefri');

    expect(pdoLegacyPlaceholderCount($statement['query']))
        ->toBe(count($statement['bindings']));
});

it('never closes a SQL string literal on a backslash', function () {
    // The precise shape ext/pdo mis-tokenises. Asserting the shape as well as
    // the count keeps the failure message legible when someone reintroduces it.
    $statement = capturedPatientSearchStatement([$this->ldk2->id], 'Jefri');

    expect($statement['query'])->not->toContain("\\'");
});

it('keeps the LIKE escape character and the escaping helper in agreement', function () {
    // A literal `%` must still be matched literally. If the clause and the
    // helper ever drift apart, one typed wildcard becomes a full-table scan.
    Patient::factory()->create([
        'name' => 'Ratna 100% Sehat',
        'medical_record_number' => 'DG-LDK2-2026-0777',
        'branch_id' => $this->ldk2->id,
    ]);

    $hit = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => '100%']))
        ->assertOk()
        ->json('results');

    expect($hit)->toHaveCount(1)
        ->and($hit[0]['name'])->toBe('Ratna 100% Sehat');

    // The escape character must escape ITSELF, or choosing a printable one
    // would quietly make any patient whose name contains it unfindable.
    Patient::factory()->create([
        'name' => 'Halo! Dunia',
        'medical_record_number' => 'DG-LDK2-2026-0778',
        'branch_id' => $this->ldk2->id,
    ]);

    $escapeChar = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Halo!']))
        ->assertOk()
        ->json('results');

    expect($escapeChar)->toHaveCount(1)
        ->and($escapeChar[0]['name'])->toBe('Halo! Dunia');

    // A backslash left the escape list when the escape character changed. With
    // an explicit non-backslash ESCAPE it is an ordinary character to LIKE on
    // both engines, so it must still match itself and nothing more.
    Patient::factory()->create([
        'name' => 'Rina\\Backslash',
        'medical_record_number' => 'DG-LDK2-2026-0779',
        'branch_id' => $this->ldk2->id,
    ]);

    $backslash = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Rina\\B']))
        ->assertOk()
        ->json('results');

    expect($backslash)->toHaveCount(1)
        ->and($backslash[0]['name'])->toBe('Rina\\Backslash');

    // `%` alone is a wildcard only if the escaping is broken.
    $wildcard = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => '%%']))
        ->assertOk()
        ->json('results');

    expect($wildcard)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 2. The runtime — on whatever connection the gate is pointed at
// ---------------------------------------------------------------------------

it('returns matching patients for a name search instead of failing', function () {
    $response = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']));

    $response->assertOk()->assertHeader('content-type', 'application/json');

    expect($response->json('searched'))->toBeTrue()
        ->and(collect($response->json('results'))->pluck('id'))->toContain($this->jefri->id);
});

it('returns the matching patient for a Nomor RM search instead of failing', function () {
    $response = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => '2026-0451']));

    $response->assertOk();

    expect(collect($response->json('results'))->pluck('id'))->toContain($this->jefri->id);
});

it('treats a query that matches nobody as a SUCCESSFUL empty result', function () {
    // The production failure was indistinguishable from this state in the UI.
    // They must never again share a response.
    $response = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Zzzznobody']));

    $response->assertOk();

    expect($response->json('searched'))->toBeTrue()
        ->and($response->json('too_short'))->toBeFalse()
        ->and($response->json('results'))->toBe([]);
});

it('answers every realistic keystroke of a name without a runtime error', function () {
    // The operator types one character at a time; every prefix hit the same
    // broken statement in production.
    foreach (['Je', 'Jef', 'Jefr', 'Jefri', 'Jefri S', 'jefri salim'] as $prefix) {
        $this->actingAs($this->actor)
            ->getJson(route('rme.visits.patient-search', ['q' => $prefix]))
            ->assertOk();
    }
});

it('rejects a malformed query parameter safely rather than with a runtime error', function () {
    $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search').'?q[]=Jefri')
        ->assertStatus(422);
});

it('still refuses to leak contact or identity fields on a successful search', function () {
    $body = $this->actingAs($this->actor)
        ->getJson(route('rme.visits.patient-search', ['q' => 'Jefri']))
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('081234500000');

    foreach (['phone', 'whatsapp', 'ktp', 'nik', 'address', 'date_of_birth', 'email'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});
