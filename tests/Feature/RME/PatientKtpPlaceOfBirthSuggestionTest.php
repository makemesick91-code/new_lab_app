<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.2 (rule 244 §5.9, RM PR-5) — the
| KTP OCR place of birth is a suggestion beside the "Tempat Lahir" field
|--------------------------------------------------------------------------
|
| The KTP read already returns the place of birth (`fields.birth_place` from
| the parse endpoint); "Terapkan ke Formulir" never applies it. RM PR-5 offers
| it beside the place-of-birth field of both new-patient forms (Master Data →
| Tambah Pasien and Kunjungan → Pasien Baru) as "Saran dari KTP: … — Gunakan".
| The browser logic (shown only when it passes the server's single-line rule,
| written as text, applied only by the operator's click, never auto-filled, no
| request, log or storage) is pinned by
| tests/js/ktp-place-of-birth-suggestion.test.mjs, run by the quality gate.
|
| What the server decides is pinned here: the slot exists on exactly the two
| new-patient surfaces, right beside the field, empty and hidden, with a plain
| button and no script, URL or OCR text; the edit page has none; and the
| browser's format rule is the server's (a change to one fails this suite
| until the other follows). The server still validates what is submitted
| (FrontOfficeNewPatientCoreFieldsTest CF-07). All identities are FICTIONAL.
|
| Selected by the existing `PatientKtp` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `kpob` is unique because the critical gate loads every suite into one
| process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\KtpOcrParser;
use App\Modules\Patient\Support\PatientCoreFields;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    seedAccessControl();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-09 02:00:00')); // 10:00 WITA, inside the pilot window
    ktpPilotScope();
    ktpPilotFlag(false);
});

afterEach(fn () => Carbon::setTestNow());

/** Parse rendered HTML into an XPath over the real element tree. */
function kpobXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** @return list<string> the element's class tokens */
function kpobClasses(DOMElement $element): array
{
    return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** The one suggestion slot, after asserting the page has exactly one. */
function kpobSlot(DOMXPath $xpath): DOMElement
{
    $slots = $xpath->query('//*[@data-ktp-pob-suggestion]');
    expect($slots->length)->toBe(1);

    return $slots->item(0);
}

it('offers the place-of-birth suggestion slot right beside the field on both new-patient forms', function (string $routeName, string $field): void {
    $operator = ktpPilotOperator();

    $html = $this->actingAs($operator)->get(route($routeName))->assertOk()->getContent();
    $xpath = kpobXPath($html);
    $slot = kpobSlot($xpath);
    $inputs = $xpath->query('//input[@name="'.$field.'"]');

    expect($inputs->length)->toBe(1);
    $input = $inputs->item(0);

    // Beside the field: the same wrapper, after the input — not in the scan section.
    expect($slot->parentNode->isSameNode($input->parentNode))->toBeTrue()
        ->and($xpath->query('preceding-sibling::input[@name="'.$field.'"]', $slot)->length)->toBe(1)
        ->and($xpath->query('ancestor::*[@data-ktp-scan]', $slot)->length)->toBe(0);

    // Empty and hidden until a KTP read offers something; a plain button, the
    // replacement marker hidden too.
    $value = $xpath->query('.//*[@data-ktp-pob-suggestion-value]', $slot);
    $use = $xpath->query('.//button[@data-ktp-pob-suggestion-use]', $slot);
    $replaces = $xpath->query('.//*[@data-ktp-pob-suggestion-replaces]', $slot);
    $check = $xpath->query('.//*[@data-ktp-pob-suggestion-check]', $slot);

    expect(kpobClasses($slot))->toContain('hidden')
        ->and($value->length)->toBe(1)
        ->and($value->item(0)->textContent)->toBe('')
        ->and($use->length)->toBe(1)
        ->and($use->item(0)->getAttribute('type'))->toBe('button')
        ->and(trim($use->item(0)->textContent))->toBe('Gunakan')
        ->and($replaces->length)->toBe(1)
        ->and(kpobClasses($replaces->item(0)))->toContain('hidden')
        // RM PR-5 review LOW-2: the low-confidence marker, shown by the browser only for such a read.
        ->and($check->length)->toBe(1)
        ->and(kpobClasses($check->item(0)))->toContain('hidden')
        ->and(trim($check->item(0)->textContent))->toBe('(perlu dicek)')
        ->and($slot->textContent)->toContain('Saran dari KTP:');
})->with([
    'Master Data → Tambah Pasien' => ['settings.patients.create', 'place_of_birth'],
    'Kunjungan → Pasien Baru' => ['rme.visits.create', 'new_patient[place_of_birth]'],
]);

it('places the visit-form slot inside the new-patient panel only', function (): void {
    $operator = ktpPilotOperator();

    $xpath = kpobXPath($this->actingAs($operator)->get(route('rme.visits.create'))->assertOk()->getContent());
    $slot = kpobSlot($xpath);

    $panels = [];
    foreach ($xpath->query('ancestor::*[@data-mode-panel]', $slot) as $ancestor) {
        $panels[] = $ancestor->getAttribute('data-mode-panel');
    }

    expect($panels)->toBe(['new']);
});

it('never fills the slot from the server, even when a failed submit replays the form', function (string $routeName, array $old): void {
    $operator = ktpPilotOperator();

    $html = $this->actingAs($operator)
        ->withSession(['_old_input' => $old])
        ->get(route($routeName))
        ->assertOk()
        ->getContent();
    $xpath = kpobXPath($html);
    $slot = kpobSlot($xpath);

    // The typed value is refilled into the FIELD (as before) — never offered as a suggestion.
    expect($xpath->query('.//*[@data-ktp-pob-suggestion-value]', $slot)->item(0)->textContent)->toBe('')
        ->and(kpobClasses($slot))->toContain('hidden')
        ->and($slot->textContent)->not->toContain('Pare-Pare')
        ->and($html)->toContain('value="Pare-Pare"');

    // No script, URL, handler or data value rides on the slot.
    expect($xpath->query('.//script | .//a | .//form | .//input', $slot)->length)->toBe(0);
    foreach ($xpath->query('descendant-or-self::*', $slot) as $element) {
        foreach ($element->attributes as $attribute) {
            expect($attribute->name)->not->toStartWith('on')
                ->and($attribute->name)->not->toBeIn(['href', 'src', 'action', 'formaction', 'data-url', 'x-html'])
                ->and($attribute->value)->not->toContain('Pare-Pare');
        }
    }
})->with([
    'Master Data → Tambah Pasien' => ['settings.patients.create', ['place_of_birth' => 'Pare-Pare', 'ktp_ocr_applied' => '1']],
    'Kunjungan → Pasien Baru' => ['rme.visits.create', ['new_patient' => ['place_of_birth' => 'Pare-Pare'], 'patient_mode' => 'new', 'ktp_ocr_applied' => '1']],
]);

it('renders the slot for an operator outside the OCR pilot too, inert: no read ever fills it', function (): void {
    $outsider = userWith(['manage patients']);

    $html = $this->actingAs($outsider)->get(route('settings.patients.create'))->assertOk()->getContent();
    $slot = kpobSlot(kpobXPath($html));

    expect(kpobClasses($slot))->toContain('hidden')
        ->and($html)->not->toContain('data-ktp-camera-open');
});

it('offers no suggestion on an existing patient page', function (): void {
    $branch = Branch::factory()->create(['code' => 'KPB1', 'is_active' => true, 'is_rme_enabled' => true]);
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'place_of_birth' => 'Maros']);

    $html = $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.edit', $patient))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="place_of_birth"')
        ->and($html)->not->toContain('data-ktp-pob-suggestion')
        ->and($html)->not->toContain('Saran dari KTP');
});

it('keeps the browser format rule identical to the server place-of-birth rule', function (): void {
    $js = (string) file_get_contents(resource_path('js/ktp-camera-ocr.js'));

    expect(preg_match('/export const PLACE_OF_BIRTH_MAX = (\d+);/', $js, $max))->toBe(1)
        ->and((int) $max[1])->toBe(PatientCoreFields::PLACE_OF_BIRTH_MAX)
        ->and(preg_match('#export const PLACE_OF_BIRTH_PATTERN = /(.+)/u;#', $js, $pattern))->toBe(1)
        ->and('/'.$pattern[1].'/u')->toBe(PatientCoreFields::SINGLE_LINE_PATTERN);

    // The server rule the browser mirrors: required at registration, one line, ≤ the same maximum.
    expect(PatientCoreFields::placeOfBirthRules(required: true))
        ->toBe(['required', 'string', 'max:'.PatientCoreFields::PLACE_OF_BIRTH_MAX, 'regex:'.PatientCoreFields::SINGLE_LINE_PATTERN]);
});

it('adds no route, request field or parser output: the parse endpoint keeps the place of birth out of the applied form', function (): void {
    $parser = (string) file_get_contents(app_path('Modules/Patient/Services/KtpOcrParser.php'));
    $js = (string) file_get_contents(resource_path('js/ktp-camera-ocr.js'));

    // "Terapkan ke Formulir" applies the parser's FORM_FIELDS only; the place
    // of birth stays a separately clicked suggestion.
    expect(KtpOcrParser::FORM_FIELDS)->not->toHaveKey('place_of_birth')
        ->and(array_values(KtpOcrParser::FORM_FIELDS))->not->toContain('birth_place')
        ->and($js)->toContain("export const FORM_FIELDS = ['ktp_number', 'name', 'date_of_birth', 'gender', 'address', 'occupation'];")
        ->and($parser)->toContain("'birth_place'");

    $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => str_contains($name, 'place-of-birth') || str_contains($name, 'place_of_birth') || str_contains($name, 'birth-place'));

    expect($names->all())->toBe([]);
});
