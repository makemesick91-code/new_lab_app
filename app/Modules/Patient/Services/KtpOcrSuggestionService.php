<?php

namespace App\Modules\Patient\Services;

/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — reconciles two OCR reads of one
 * KTP into suggestions (rule 179).
 *
 * The browser reads the card twice: once as a whole (the preserved,
 * original read: labelled `lines`) and once per field region (`fields`: the
 * value text of each box, no label). Both reads go through the SAME parser and
 * validators; this class only decides what to offer when they are compared:
 *
 *   both valid and equal  -> `agree`          the value, clean if either read was clean
 *   only one valid        -> `field_only` / `document_only`, under that read's own status —
 *                            EXCEPT when the other read saw the label twice with
 *                            different values (`ambiguous`): it disagreed with itself,
 *                            so the single value is offered, never pre-selected
 *   both valid, different -> `conflict`       value null, both listed, NOTHING selected
 *   neither valid         -> `none`           the more informative failure
 *
 * A disagreement is never settled by OCR confidence: a confident misread is
 * exactly what the field read exists to catch. The operator chooses.
 *
 * Like the parser it is pure — no database, filesystem, network or logging —
 * and the result is a suggestion the operator still applies and confirms.
 */
class KtpOcrSuggestionService
{
    /**
     * Field regions the browser may send, and the label the SERVER puts in
     * front of each value. The label is never taken from the request, and a
     * read is only passed through unlabelled when the parser itself would file
     * it under that same field, so a field read cannot address a different field.
     */
    public const FIELD_LABELS = [
        'nik' => 'NIK',
        'name' => 'NAMA',
        'birth_place_date' => 'TEMPAT/TGL LAHIR',
        'gender' => 'JENIS KELAMIN',
        'address' => 'ALAMAT',
        'rt_rw' => 'RT/RW',
        'village' => 'KEL/DESA',
        'district' => 'KECAMATAN',
        'religion' => 'AGAMA',
        'marital_status' => 'STATUS PERKAWINAN',
        'occupation' => 'PEKERJAAN',
    ];

    /** Failure statuses, most informative first, for a field neither read produced. */
    private const FAILURE_RANK = [
        KtpOcrParser::STATUS_AMBIGUOUS => 3,
        KtpOcrParser::STATUS_INVALID => 2,
        KtpOcrParser::STATUS_LOW_CONFIDENCE => 1,
        KtpOcrParser::STATUS_MISSING => 0,
    ];

    public function __construct(private readonly KtpOcrParser $parser) {}

    /**
     * @param  list<array{text: string, confidence: float|int|null}>  $lines  whole-card read
     * @param  array<string, array{text: ?string, confidence: float|int|null}>|null  $fields  per-field read
     */
    public function suggest(array $lines, ?array $fields, float $threshold): array
    {
        if ($fields === null || $fields === []) {
            // No field read (scanner agent, older page, ROI step failed): the
            // single-read result in its original shape. Values match the
            // previous release except where a misread RT/RW label ("RTIRW") is
            // now recognised, which only adds a part that used to be dropped.
            return $this->parser->parse($lines, $threshold);
        }

        $document = $this->parser->parseFields($lines, $threshold);
        $field = $this->parser->parseFields($this->fieldLines($fields), $threshold);

        $merged = [];
        foreach (KtpOcrParser::FIELDS as $key) {
            $merged[$key] = $this->reconcile($field[$key], $document[$key]);
        }

        return $this->parser->finalize($merged, $threshold) + ['method' => 'hybrid'];
    }

    /**
     * Turn per-field value text into labelled lines the parser understands.
     * Text that already starts with its OWN label (a box moved over the label)
     * is used as-is rather than labelled twice — but only when the parser's
     * first-match rule files that line under the SAME field. "KELAMIN DESA : …"
     * matches the village pattern yet the parser reads it as gender, so it
     * gets the village label in front instead.
     *
     * @param  array<string, array{text: ?string, confidence: float|int|null}>  $fields
     * @return list<array{text: string, confidence: float|int|null}>
     */
    private function fieldLines(array $fields): array
    {
        $lines = [];
        foreach (self::FIELD_LABELS as $key => $label) {
            $text = trim((string) ($fields[$key]['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = [
                'text' => $this->parser->labelKeyOf($text) === $key ? $text : $label.' : '.$text,
                'confidence' => $fields[$key]['confidence'] ?? null,
            ];
        }

        return $lines;
    }

    /**
     * @param  array{value: ?string, status: string, confidence: ?float, reasons: list<string>}  $field
     * @param  array{value: ?string, status: string, confidence: ?float, reasons: list<string>}  $document
     */
    private function reconcile(array $field, array $document): array
    {
        $fieldValue = $field['value'];
        $documentValue = $document['value'];

        if ($fieldValue === null && $documentValue === null) {
            $base = (self::FAILURE_RANK[$field['status']] ?? 0) > (self::FAILURE_RANK[$document['status']] ?? 0)
                ? $field
                : $document;

            return $base + ['agreement' => 'none'];
        }

        if ($documentValue === null) {
            return $this->unconfirmedBy($field, $document) + ['agreement' => 'field_only'];
        }

        if ($fieldValue === null) {
            return $this->unconfirmedBy($document, $field) + ['agreement' => 'document_only'];
        }

        if ($this->same($fieldValue, $documentValue)) {
            // Two reads of different pixel regions, with different engine
            // settings, produced the same validated value: that agreement is
            // itself evidence, so one clean read makes the field clean.
            $clean = $field['status'] === KtpOcrParser::STATUS_OK || $document['status'] === KtpOcrParser::STATUS_OK;
            $base = $field['status'] === KtpOcrParser::STATUS_OK ? $field : $document;

            return array_merge($base, [
                'status' => $clean ? KtpOcrParser::STATUS_OK : KtpOcrParser::STATUS_LOW_CONFIDENCE,
                'reasons' => $clean ? [] : array_values(array_unique(array_merge($field['reasons'], $document['reasons']))),
                'agreement' => 'agree',
            ]);
        }

        return [
            'value' => null,
            'status' => KtpOcrParser::STATUS_CONFLICT,
            'confidence' => null,
            'reasons' => ['ocr_methods_disagree'],
            'agreement' => 'conflict',
            'alternatives' => [
                ['source' => 'field', 'value' => $fieldValue, 'status' => $field['status'], 'confidence' => $field['confidence']],
                ['source' => 'document', 'value' => $documentValue, 'status' => $document['status'], 'confidence' => $document['confidence']],
            ],
        ];
    }

    /**
     * The only read with a value stands under its own status, unless the other
     * read was ambiguous — it found the label more than once with different
     * values, which is a disagreement in itself. Then the value is offered but
     * never pre-selected: the single read cannot settle what the other could not.
     *
     * @param  array{value: ?string, status: string, confidence: ?float, reasons: list<string>}  $read
     * @param  array{value: ?string, status: string, confidence: ?float, reasons: list<string>}  $other
     */
    private function unconfirmedBy(array $read, array $other): array
    {
        if ($other['status'] !== KtpOcrParser::STATUS_AMBIGUOUS || $read['status'] !== KtpOcrParser::STATUS_OK) {
            return $read;
        }

        return array_merge($read, [
            'status' => KtpOcrParser::STATUS_LOW_CONFIDENCE,
            'reasons' => array_values(array_unique(array_merge($read['reasons'], ['other_read_ambiguous']))),
        ]);
    }

    private function same(string $a, string $b): bool
    {
        $normalize = fn (string $v): string => mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $v) ?? ''), 'UTF-8');

        return $normalize($a) === $normalize($b);
    }
}
