<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\Patient\Services\PatientMedicalRecordNumberService;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — turning what a human typed off a piece of
 * paper into the canonical form the identity resolver matches on.
 *
 * THE ONE RULE THIS CLASS EXISTS TO OBEY:
 *
 *     NORMALIZATION MAY REPAIR TRANSCRIPTION. IT MAY NEVER CHANGE A DIGIT.
 *
 * `27541` must never become `22541`, must never become `2754`, and must never
 * gain or lose a leading zero. Every transformation below is therefore confined
 * to characters that carry no identity: surrounding whitespace, whitespace
 * hugging a separator, and dash characters a word processor or a phone keyboard
 * substituted for a plain hyphen. Digits, letters and their ORDER are carried
 * through untouched, and the manual segment — the part that actually
 * distinguishes one patient from another — is never re-cased, never padded and
 * never trimmed of significant characters.
 *
 * WHY THE CANONICAL REBUILD IS SAFE. When the value parses as a real Nomor RM,
 * it is rebuilt through {@see PatientMedicalRecordNumberService::compose()} —
 * the exact function that produced every stored value in the first place.
 * `parse()` is documented as compose()'s inverse, so `compose(parse($x))` is
 * `$x` for anything compose() could have produced. Case folding therefore
 * touches only the prefix and the branch code, which compose() itself uppercases;
 * the sequence is passed through verbatim, exactly as parse() returned it.
 *
 * WHAT IT DOES NOT DO. It does not search, does not decide identity, does not
 * consult the patient master and does not know what a branch is. It turns text
 * into text. Deciding whether the result names a patient — and WHICH patient —
 * belongs to {@see LegacyRmeSourcePatientBindingService}, which is the only
 * component allowed to answer that.
 */
class LegacyRmeSourceRmNormalizer
{
    /**
     * Dash characters that routinely substitute for a plain hyphen when a value
     * is retyped from paper, copied out of a word processor, or entered on a
     * phone keyboard. Folding them is a transcription repair: none of them can
     * appear in a value {@see PatientMedicalRecordNumberService::compose()}
     * produced, so nothing legitimate is rewritten.
     *
     * @var list<string>
     */
    private const DASH_VARIANTS = [
        "\u{2010}", // hyphen
        "\u{2011}", // non-breaking hyphen
        "\u{2012}", // figure dash
        "\u{2013}", // en dash
        "\u{2014}", // em dash
        "\u{2015}", // horizontal bar
        "\u{2212}", // minus sign
        "\u{FE58}", // small em dash
        "\u{FE63}", // small hyphen-minus
        "\u{FF0D}", // fullwidth hyphen-minus
    ];

    public function __construct(
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
    ) {}

    /**
     * The canonical form, or null when the input is empty once the noise is gone.
     *
     * Returning null rather than an empty string keeps "nothing was entered"
     * a distinct outcome from "something illegible was entered", because those
     * are different operator problems with different remedies.
     */
    public function normalize(?string $value): ?string
    {
        $cleaned = $this->stripTranscriptionNoise((string) $value);

        if ($cleaned === '') {
            return null;
        }

        // Already a canonical Nomor RM: rebuild through the composer so casing
        // and spacing match a stored value exactly. The sequence is carried
        // verbatim out of parse().
        $parts = $this->medicalRecordNumbers->parse($cleaned);

        if ($parts !== null) {
            return $this->medicalRecordNumbers->compose($parts->branchCode, $parts->year, $parts->sequence);
        }

        // A lowercase transcription (`dg-tkm1-2024-9985`). The branch-code
        // pattern parse() enforces is uppercase-only, so the value is retried
        // with ONLY the prefix and branch-code segments folded — the year and
        // the sequence keep the characters the operator actually entered.
        $recased = $this->recaseLeadingSegments($cleaned);

        if ($recased !== null) {
            $parts = $this->medicalRecordNumbers->parse($recased);

            if ($parts !== null) {
                return $this->medicalRecordNumbers->compose($parts->branchCode, $parts->year, $parts->sequence);
            }
        }

        // Not a canonical Nomor RM — most often the bare manual number as it is
        // printed on an old document (`9985`). It is returned as-is: the
        // resolver matches a bare value against the WHOLE manual segment of a
        // canonical number, which is a decision this class must not pre-empt.
        return $cleaned;
    }

    /**
     * Whitespace and dash-variant repair. Nothing here can add, remove or
     * reorder an alphanumeric character.
     */
    private function stripTranscriptionNoise(string $value): string
    {
        // Unicode spaces (NBSP and friends) first, so the whitespace rules below
        // see a single, ordinary space everywhere.
        $value = preg_replace('/[\p{Zs}\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', $value) ?? $value;

        $value = str_replace(self::DASH_VARIANTS, '-', $value);

        // Spacing AROUND a separator only — `DG - TKM1 - 2024 - 9985`.
        //
        // Internal whitespace that is NOT adjacent to a hyphen is deliberately
        // left alone. compose() accepts any non-empty trimmed string as the
        // manual segment, so a sequence could in principle contain a space, and
        // silently deleting it would be changing the identity rather than the
        // transcription. Such a value simply fails to match and is refused,
        // which is the correct direction to be wrong in.
        $value = preg_replace('/\s*-\s*/u', '-', $value) ?? $value;

        return trim($value);
    }

    /**
     * Uppercase the prefix and branch-code segments of a four-part value,
     * leaving the year and the manual sequence exactly as entered.
     *
     * Returns null when the value is not four-part, so the caller falls through
     * to treating it as a bare manual number rather than mangling it.
     */
    private function recaseLeadingSegments(string $value): ?string
    {
        $parts = explode('-', $value, 4);

        if (count($parts) !== 4) {
            return null;
        }

        return mb_strtoupper($parts[0]).'-'.mb_strtoupper($parts[1]).'-'.$parts[2].'-'.$parts[3];
    }
}
