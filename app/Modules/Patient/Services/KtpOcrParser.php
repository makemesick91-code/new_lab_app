<?php

namespace App\Modules\Patient\Services;

use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;

/**
 * REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — Indonesian KTP OCR field parser.
 *
 * Turns the raw text lines an OCR engine read from a KTP photo into SUGGESTED
 * registration values. It is pure: no database, no filesystem, no network, no
 * logging. The OCR engine itself runs in the operator's browser (self-hosted
 * tesseract.js); this class is the server-side authority that decides what the
 * text actually supports.
 *
 * Contract — every rule below exists so OCR can never invent data:
 *   - A field is extracted only from a line carrying its own KTP label. A value
 *     is never inferred from a different field (the birth date is NOT derived
 *     from the NIK; the NIK is used only to CROSS-CHECK what was read).
 *   - A field that is absent is reported `missing` with a null value.
 *   - A value that fails its format check is reported `invalid` with a null
 *     value — an invalid value is never offered for the form.
 *   - A value read below the confidence threshold, or one that needed glyph
 *     correction, is reported `low_confidence`: offered, but never pre-selected.
 *   - A label seen twice with different values is `ambiguous` with a null value.
 *
 * The output is a SUGGESTION. It writes nothing; the operator applies, edits
 * and confirms, and the registration request is validated by the same rules
 * as manual entry.
 */
class KtpOcrParser
{
    public const STATUS_OK = 'ok';

    public const STATUS_LOW_CONFIDENCE = 'low_confidence';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_MISSING = 'missing';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    /**
     * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — two independent reads of the
     * same field produced two different valid values. The value is null and both
     * candidates are listed under `alternatives`; nothing is pre-selected.
     */
    public const STATUS_CONFLICT = 'conflict';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_PARTIAL = 'partial';

    public const OUTCOME_FAILED = 'failed';

    /** Every field the parser looks for, in KTP order. */
    public const FIELDS = [
        'nik', 'name', 'birth_place', 'date_of_birth', 'gender', 'address',
        'rt_rw', 'village', 'district', 'religion', 'marital_status', 'occupation',
    ];

    /**
     * The registration form fields an OCR result may populate. Only columns that
     * already exist on mst_patients — nothing else is offered for the form.
     *
     * @var array<string, string> form field => parser field
     */
    public const FORM_FIELDS = [
        'ktp_number' => 'nik',
        'name' => 'name',
        'date_of_birth' => 'date_of_birth',
        'gender' => 'gender',
        'address' => 'address',
        'occupation' => 'occupation',
    ];

    /** Label patterns, anchored at the start of a normalized line. */
    public const LABELS = [
        'nik' => '/^N[I1L|!]K\b/',
        'name' => '/^NAMA\b/',
        'birth_place_date' => '/^(?:TEMP\S*|TGL)\s*[\/.]?\s*\S*\s*LAH[I1L]R\b|^TEMPAT\b/',
        'gender' => '/^JEN[I1L]S\s*KELAM[I1L]N\b|^KELAM[I1L]N\b/',
        'address' => '/^ALAMAT\b/',
        // The slash is often read as I, 1, L or | ("RTIRW") — measured on the
        // ROI benchmark; tolerated like the NIK label's I/1/L.
        'rt_rw' => '/^RT\s*[\/.I1L|!]?\s*RW\b/',
        'village' => '/^KEL\S*\s*[\/.]?\s*DESA\b|^DESA\b|^KELURAHAN\b/',
        'district' => '/^KECAMATAN\b/',
        'religion' => '/^AGAMA\b/',
        'marital_status' => '/^STATUS\s*PERKAW[I1L]NAN\b|^PERKAW[I1L]NAN\b/',
        'occupation' => '/^PEKERJAAN\b/',
    ];

    private const RELIGIONS = [
        'ISLAM' => 'ISLAM', 'KRISTEN' => 'KRISTEN', 'KATOLIK' => 'KATOLIK', 'KATHOLIK' => 'KATOLIK',
        'HINDU' => 'HINDU', 'BUDDHA' => 'BUDDHA', 'BUDHA' => 'BUDDHA',
        'KHONGHUCU' => 'KHONGHUCU', 'KONGHUCU' => 'KHONGHUCU',
        'KEPERCAYAAN TERHADAP TUHAN YME' => 'KEPERCAYAAN TERHADAP TUHAN YME',
    ];

    private const MARITAL = ['BELUM KAWIN', 'KAWIN', 'CERAI HIDUP', 'CERAI MATI'];

    /** Glyphs OCR commonly returns for a digit inside the NIK line only. */
    private const NIK_GLYPHS = [
        'O' => '0', 'D' => '0', 'Q' => '0', 'U' => '0',
        'I' => '1', 'L' => '1', '|' => '1', '!' => '1',
        'Z' => '2', 'S' => '5', 'G' => '6', 'B' => '8', 'T' => '7',
    ];

    public function __construct(private readonly ClinicalClock $clock) {}

    /**
     * @param  list<array{text: string, confidence: float|int|null}>  $lines
     * @return array{
     *     outcome: string,
     *     fields: array<string, array{value: ?string, status: string, confidence: ?float, reasons: list<string>}>,
     *     form: array<string, array{value: ?string, status: string, source: string, suggest: bool}>,
     *     recognized_form_fields: int,
     *     threshold: float,
     * }
     */
    public function parse(array $lines, float $threshold): array
    {
        return $this->finalize($this->parseFields($lines, $threshold), $threshold);
    }

    /**
     * Read and validate every field from labelled lines, WITHOUT the NIK
     * cross-check. Used directly when several reads are reconciled before the
     * cross-check runs once on the result ({@see KtpOcrSuggestionService}).
     *
     * @param  list<array{text: string, confidence: float|int|null}>  $lines
     * @return array<string, array{value: ?string, status: string, confidence: ?float, reasons: list<string>}>
     */
    public function parseFields(array $lines, float $threshold): array
    {
        $found = $this->collectLabelledValues($lines);

        $fields = array_fill_keys(self::FIELDS, null);
        $fields['nik'] = $this->parseNik($found['nik'] ?? null, $threshold);
        $fields['name'] = $this->parseName($found['name'] ?? null, $threshold);
        [$fields['birth_place'], $fields['date_of_birth']] = $this->parseBirth($found['birth_place_date'] ?? null, $threshold);
        $fields['gender'] = $this->parseGender($found['gender'] ?? null, $threshold);
        $fields['address'] = $this->parseFreeText($found['address'] ?? null, $threshold, 1000);
        $fields['rt_rw'] = $this->parseRtRw($found['rt_rw'] ?? null, $threshold);
        $fields['village'] = $this->parseFreeText($found['village'] ?? null, $threshold, 150);
        $fields['district'] = $this->parseFreeText($found['district'] ?? null, $threshold, 150);
        $fields['religion'] = $this->parseEnum($found['religion'] ?? null, $threshold, self::RELIGIONS);
        $fields['marital_status'] = $this->parseEnum($found['marital_status'] ?? null, $threshold, array_combine(self::MARITAL, self::MARITAL));
        $fields['occupation'] = $this->parseFreeText($found['occupation'] ?? null, $threshold, 150);

        return $fields;
    }

    /**
     * Cross-check, build the form suggestions and decide the outcome.
     *
     * A field in conflict counts as recognized (the operator has candidates to
     * choose from) but never as a clean read.
     *
     * @param  array<string, array>  $fields
     */
    public function finalize(array $fields, float $threshold): array
    {
        $this->crossCheckNik($fields);

        $form = $this->buildFormSuggestions($fields);
        $recognized = count(array_filter(
            $form,
            fn (array $f): bool => $f['value'] !== null || ($f['alternatives'] ?? []) !== [],
        ));

        $okCount = count(array_filter($form, fn (array $f): bool => $f['status'] === self::STATUS_OK));
        $outcome = match (true) {
            $recognized === 0 => self::OUTCOME_FAILED,
            $okCount === count(self::FORM_FIELDS) => self::OUTCOME_SUCCESS,
            default => self::OUTCOME_PARTIAL,
        };

        return [
            'outcome' => $outcome,
            'fields' => $fields,
            'form' => $form,
            'recognized_form_fields' => $recognized,
            'threshold' => $threshold,
        ];
    }

    /**
     * Find, for each label, the value text after it. A label seen twice with
     * DIFFERENT values is recorded as ambiguous rather than picking one.
     *
     * @param  list<array{text: string, confidence: float|int|null}>  $lines
     * @return array<string, array{text: ?string, confidence: ?float, ambiguous: bool}>
     */
    private function collectLabelledValues(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            $text = $this->normalize((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            foreach (self::LABELS as $key => $pattern) {
                if (preg_match($pattern, $text, $m) !== 1) {
                    continue;
                }

                $value = $this->valueAfterLabel($text, $m[0]);
                $confidence = isset($line['confidence']) && is_numeric($line['confidence'])
                    ? max(0.0, min(100.0, (float) $line['confidence']))
                    : null;

                if (isset($found[$key])) {
                    if ($found[$key]['text'] !== $value) {
                        $found[$key]['ambiguous'] = true;
                    }
                } else {
                    $found[$key] = ['text' => $value, 'confidence' => $confidence, 'ambiguous' => false];
                }

                break; // one label per line
            }
        }

        return $found;
    }

    /**
     * The field whose label a line starts with, by the same first-match rule
     * {@see collectLabelledValues()} applies, or null when no label matches.
     */
    public function labelKeyOf(string $text): ?string
    {
        $normalized = $this->normalize($text);
        foreach (self::LABELS as $key => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $key;
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtoupper(trim($text), 'UTF-8');
        // Drop control characters; collapse whitespace.
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function valueAfterLabel(string $line, string $label): ?string
    {
        $rest = substr($line, strlen($label));
        // A colon is the KTP separator; OCR sometimes reads it as "." ";" or "-".
        // A colon close after the label is the separator (it also absorbs label
        // residue such as "/TGL LAHIR :"); one far into the value is content.
        $colon = strpos($rest, ':');
        if ($colon !== false && $colon <= 12) {
            $rest = substr($rest, $colon + 1);
        }
        $rest = trim(preg_replace('/^[\s:;.\-–]+/u', '', $rest) ?? '');

        return $rest === '' ? null : $rest;
    }

    /**
     * @param  array{text: ?string, confidence: ?float, ambiguous: bool}|null  $hit
     */
    private function parseNik(?array $hit, float $threshold): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $raw = str_replace([' ', '-', '.'], '', (string) $hit['text']);
        $reasons = [];

        // Correct look-alike glyphs ONLY when the token is otherwise numeric, and
        // record it — a corrected NIK is never offered as high confidence.
        if (preg_match('/^[0-9OQDUILZSGBT|!]+$/', $raw) === 1 && preg_match('/[^0-9]/', $raw) === 1) {
            $raw = strtr($raw, self::NIK_GLYPHS);
            $reasons[] = 'nik_glyph_corrected';
        }

        if (preg_match('/^\d{16}$/', $raw) !== 1) {
            return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['nik_not_16_digits']);
        }

        $province = (int) substr($raw, 0, 2);
        $day = (int) substr($raw, 6, 2);
        $month = (int) substr($raw, 8, 2);
        if ($province < 11 || $province > 94) {
            return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['nik_province_code_invalid']);
        }
        if ($month < 1 || $month > 12 || ! (($day >= 1 && $day <= 31) || ($day >= 41 && $day <= 71))) {
            return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['nik_birth_segment_invalid']);
        }

        return $this->result($raw, $this->statusFor($hit['confidence'], $threshold, $reasons !== []), $hit['confidence'], $reasons);
    }

    private function parseName(?array $hit, float $threshold): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $value = trim((string) $hit['text']);
        if (mb_strlen($value) < 2 || preg_match('/\p{L}/u', $value) !== 1 || mb_strlen($value) > 150) {
            return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['name_unreadable']);
        }

        $reasons = [];
        if (preg_match('/[^\p{L}\s.,\'\-]/u', $value) === 1) {
            // Digits or symbols in a name are an OCR misread — flag, never strip.
            $reasons[] = 'name_contains_unexpected_characters';
        }

        return $this->result($value, $this->statusFor($hit['confidence'], $threshold, $reasons !== []), $hit['confidence'], $reasons);
    }

    /**
     * @return array{0: array, 1: array} [birth_place, date_of_birth]
     */
    private function parseBirth(?array $hit, float $threshold): array
    {
        if ($guard = $this->guardHit($hit)) {
            return [$guard, $guard];
        }

        $text = (string) $hit['text'];
        $place = null;
        $date = $this->result(null, self::STATUS_MISSING, $hit['confidence'], ['date_not_found']);

        if (preg_match('/(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{4})/', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $place = trim((string) preg_replace('/[\s,.;:\-]+$/u', '', substr($text, 0, $m[0][1])));
            $date = $this->parseDate((int) $m[1][0], (int) $m[2][0], (int) $m[3][0], $hit['confidence'], $threshold);
        } elseif (str_contains($text, ',')) {
            $place = trim(substr($text, 0, (int) strrpos($text, ',')));
        }

        $placeResult = ($place === null || $place === '' || preg_match('/\p{L}/u', $place) !== 1)
            ? $this->result(null, self::STATUS_MISSING, $hit['confidence'], [])
            : $this->result($place, $this->statusFor($hit['confidence'], $threshold, false), $hit['confidence'], []);

        return [$placeResult, $date];
    }

    private function parseDate(int $day, int $month, int $year, ?float $confidence, float $threshold): array
    {
        if (! checkdate($month, $day, $year)) {
            return $this->result(null, self::STATUS_INVALID, $confidence, ['date_not_a_calendar_date']);
        }

        $date = CarbonImmutable::createFromDate($year, $month, $day)->startOfDay();
        $today = CarbonImmutable::parse($this->clock->todayString());

        if ($date->greaterThan($today)) {
            return $this->result(null, self::STATUS_INVALID, $confidence, ['date_in_future']);
        }
        if ($year < 1900 || $date->lessThan($today->subYears(130))) {
            return $this->result(null, self::STATUS_INVALID, $confidence, ['date_implausibly_old']);
        }

        return $this->result($date->format('Y-m-d'), $this->statusFor($confidence, $threshold, false), $confidence, []);
    }

    private function parseGender(?array $hit, float $threshold): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $text = (string) $hit['text'];
        $male = preg_match('/LAK[I1L]\s*-?\s*LAK[I1L]|\bPRIA\b/', $text) === 1;
        $female = preg_match('/PEREMPUAN|WAN[I1L]TA/', $text) === 1;

        if ($male === $female) {
            // Neither read, or both read: never pick one.
            return $this->result(null, $male ? self::STATUS_AMBIGUOUS : self::STATUS_INVALID, $hit['confidence'], ['gender_unreadable']);
        }

        return $this->result($male ? 'Male' : 'Female', $this->statusFor($hit['confidence'], $threshold, false), $hit['confidence'], []);
    }

    private function parseRtRw(?array $hit, float $threshold): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $text = str_replace(' ', '', (string) $hit['text']);
        if (preg_match('/^(\d{1,3})[\/\\\\|.\-](\d{1,3})$/', $text, $m) === 1) {
            return $this->result(sprintf('%03d/%03d', (int) $m[1], (int) $m[2]), $this->statusFor($hit['confidence'], $threshold, false), $hit['confidence'], []);
        }
        if (preg_match('/^(\d{3})(\d{3})$/', $text, $m) === 1) {
            // The separator was lost; the split is the KTP's fixed 3+3 layout,
            // but it is still a reconstruction, so it is flagged.
            return $this->result($m[1].'/'.$m[2], self::STATUS_LOW_CONFIDENCE, $hit['confidence'], ['rt_rw_separator_missing']);
        }

        return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['rt_rw_unreadable']);
    }

    private function parseFreeText(?array $hit, float $threshold, int $max): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $value = trim((string) $hit['text']);
        if (preg_match('/[\p{L}\d]/u', $value) !== 1 || mb_strlen($value) > $max) {
            return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['value_unreadable']);
        }

        return $this->result($value, $this->statusFor($hit['confidence'], $threshold, false), $hit['confidence'], []);
    }

    /**
     * @param  array<string, string>  $allowed  read form => canonical
     */
    private function parseEnum(?array $hit, float $threshold, array $allowed): array
    {
        if ($guard = $this->guardHit($hit)) {
            return $guard;
        }

        $value = trim((string) $hit['text']);
        // Longest key first so "BELUM KAWIN" never matches as "KAWIN".
        $keys = array_keys($allowed);
        usort($keys, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            if (str_starts_with($value, $key)) {
                return $this->result($allowed[$key], $this->statusFor($hit['confidence'], $threshold, false), $hit['confidence'], []);
            }
        }

        return $this->result(null, self::STATUS_INVALID, $hit['confidence'], ['value_not_recognized']);
    }

    /**
     * The NIK encodes the birth date (DD+40 for women) and the gender. When both
     * sides were read, disagreement means one of them is a misread: BOTH are
     * demoted to low confidence. Nothing is ever filled in from the NIK.
     *
     * @param  array<string, array>  $fields
     */
    private function crossCheckNik(array &$fields): void
    {
        // A field in conflict has no single value, but each of its candidates
        // is still a validated read. Checking against every candidate keeps a
        // disagreement from switching the cross-check off: a birth date or
        // gender that contradicts EVERY NIK candidate is still demoted.
        $niks = $this->candidates($fields['nik'] ?? null);
        if ($niks === []) {
            return;
        }

        $encoded = array_map(function (string $nik): array {
            $day = (int) substr($nik, 6, 2);
            $isFemale = $day > 40;

            return [
                'day' => $isFemale ? $day - 40 : $day,
                'month' => (int) substr($nik, 8, 2),
                'yy' => substr($nik, 10, 2),
                'female' => $isFemale,
            ];
        }, $niks);

        $dobs = $this->candidates($fields['date_of_birth'] ?? null);
        if ($dobs !== []) {
            $consistent = false;
            foreach ($encoded as $n) {
                foreach ($dobs as $dob) {
                    [$y, $m, $d] = array_map('intval', explode('-', $dob));
                    if ($d === $n['day'] && $m === $n['month'] && substr((string) $y, -2) === $n['yy']) {
                        $consistent = true;
                    }
                }
            }
            if (! $consistent) {
                $this->demote($fields['nik'], 'nik_birth_date_mismatch');
                $this->demote($fields['date_of_birth'], 'nik_birth_date_mismatch');
            }
        }

        $genders = $this->candidates($fields['gender'] ?? null);
        if ($genders !== []) {
            $consistent = false;
            foreach ($encoded as $n) {
                foreach ($genders as $gender) {
                    if (($gender === 'Female') === $n['female']) {
                        $consistent = true;
                    }
                }
            }
            if (! $consistent) {
                $this->demote($fields['nik'], 'nik_gender_mismatch');
                $this->demote($fields['gender'], 'nik_gender_mismatch');
            }
        }
    }

    /**
     * The validated values a field holds: its value, or — when it is in
     * conflict — each alternative's value.
     *
     * @return list<string>
     */
    private function candidates(?array $field): array
    {
        if ($field === null) {
            return [];
        }
        if ($field['value'] !== null) {
            return [$field['value']];
        }

        return array_values(array_filter(
            array_map(fn (array $a): mixed => $a['value'] ?? null, $field['alternatives'] ?? []),
            fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
    }

    /**
     * @param  array<string, array>  $fields
     * @return array<string, array{value: ?string, status: string, source: string, suggest: bool}>
     */
    private function buildFormSuggestions(array $fields): array
    {
        $form = [];
        foreach (self::FORM_FIELDS as $formField => $source) {
            $field = $source === 'address' ? $this->composeAddress($fields) : $fields[$source];
            $form[$formField] = [
                'value' => $field['value'],
                'status' => $field['status'],
                'source' => $source,
                // Only a clean read is pre-selected; everything else needs the
                // operator to opt in after looking at the card.
                'suggest' => $field['value'] !== null && $field['status'] === self::STATUS_OK,
            ];
            // Reconciled reads only (field-based ROI): which reads agreed, and —
            // for a conflict — the candidates the operator chooses between.
            foreach (['agreement', 'alternatives'] as $extra) {
                if (array_key_exists($extra, $field)) {
                    $form[$formField][$extra] = $field[$extra];
                }
            }
        }

        return $form;
    }

    /**
     * mst_patients has ONE address column, so the KTP's address block (street,
     * RT/RW, Kel/Desa, Kecamatan) is composed into it. Only parts actually read
     * are included; the result is never better than its weakest part.
     *
     * @param  array<string, array>  $fields
     */
    private function composeAddress(array $fields): array
    {
        $parts = ['address', 'rt_rw', 'village', 'district'];
        $conflicts = array_filter($parts, fn (string $k): bool => ($fields[$k]['status'] ?? null) === self::STATUS_CONFLICT);
        if ($conflicts !== []) {
            return $this->composeConflictingAddress($fields, $conflicts);
        }

        $street = $fields['address'];
        if ($street['value'] === null) {
            return $street;
        }

        $parts = [$street['value']];
        $worst = $street['status'];
        $reasons = $street['reasons'];

        foreach ([['rt_rw', 'RT/RW '], ['village', 'KEL. '], ['district', 'KEC. ']] as [$key, $prefix]) {
            $part = $fields[$key];
            if ($part['value'] === null) {
                continue;
            }
            $parts[] = $prefix.$part['value'];
            if ($part['status'] !== self::STATUS_OK) {
                $worst = self::STATUS_LOW_CONFIDENCE;
                $reasons = array_merge($reasons, $part['reasons']);
            }
        }

        $value = implode(', ', $parts);
        if (mb_strlen($value) > 1000) {
            return $this->result(null, self::STATUS_INVALID, $street['confidence'], ['address_too_long']);
        }

        $composed = $this->result($value, $worst, $street['confidence'], array_values(array_unique($reasons)));
        if (array_key_exists('agreement', $street)) {
            $composed['agreement'] = $street['agreement'];
        }

        return $composed;
    }

    /**
     * A component of the address block was read two different ways. The
     * address is offered as two complete alternatives — one built with each
     * read's value for the disputed parts — and nothing is pre-selected.
     *
     * @param  array<string, array>  $fields
     * @param  array<int, string>  $conflicts
     */
    private function composeConflictingAddress(array $fields, array $conflicts): array
    {
        $alternatives = [];
        foreach (['field', 'document'] as $source) {
            $variant = $fields;
            foreach ($conflicts as $key) {
                $candidate = collect($fields[$key]['alternatives'] ?? [])->firstWhere('source', $source);
                $variant[$key] = $candidate === null
                    ? $this->result(null, self::STATUS_MISSING, null, [])
                    : $this->result($candidate['value'], $candidate['status'], $candidate['confidence'] ?? null, []);
            }
            $composed = $this->composeAddress($variant);
            if ($composed['value'] !== null) {
                $alternatives[] = [
                    'source' => $source,
                    'value' => $composed['value'],
                    'status' => $composed['status'],
                    'confidence' => $composed['confidence'],
                ];
            }
        }

        $result = $this->result(null, self::STATUS_CONFLICT, null, ['ocr_methods_disagree']);
        $result['agreement'] = 'conflict';
        $result['alternatives'] = $alternatives;

        return $result;
    }

    private function guardHit(?array $hit): ?array
    {
        if ($hit === null) {
            return $this->result(null, self::STATUS_MISSING, null, []);
        }
        if ($hit['ambiguous']) {
            return $this->result(null, self::STATUS_AMBIGUOUS, $hit['confidence'], ['label_read_twice_with_different_values']);
        }
        if ($hit['text'] === null) {
            return $this->result(null, self::STATUS_MISSING, $hit['confidence'], ['value_empty']);
        }

        return null;
    }

    private function statusFor(?float $confidence, float $threshold, bool $corrected): string
    {
        // Unknown confidence is treated as low: absence of a score is not a pass.
        if ($corrected || $confidence === null || $confidence < $threshold) {
            return self::STATUS_LOW_CONFIDENCE;
        }

        return self::STATUS_OK;
    }

    private function demote(array &$field, string $reason): void
    {
        if ($field['value'] === null) {
            return;
        }
        $field['status'] = self::STATUS_LOW_CONFIDENCE;
        $field['reasons'][] = $reason;
        $field['reasons'] = array_values(array_unique($field['reasons']));
    }

    /**
     * @param  list<string>  $reasons
     * @return array{value: ?string, status: string, confidence: ?float, reasons: list<string>}
     */
    private function result(?string $value, string $status, ?float $confidence, array $reasons): array
    {
        return [
            'value' => $value,
            'status' => $status,
            'confidence' => $confidence === null ? null : round($confidence, 1),
            'reasons' => $reasons,
        ];
    }
}
