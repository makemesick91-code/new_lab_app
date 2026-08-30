<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * BUGFIX-LEGACY-ODONTOGRAM-PATIENT-LOOKUP-1 — the canonical boundary for the
 * patient identifier on the upload page.
 *
 * WHAT WENT WRONG WITHOUT ONE. The controller read the identifier with
 * `$request->integer('patient_id')`, which is `intval()`. `intval()` does not
 * fail; it guesses. `'DG-TKM1-2024-0001'` became 0 and the page went blank,
 * `'1abc'` became 1, and `['anything']` also became 1 — so a malformed
 * identifier silently resolved whichever patient happened to be row #1 and
 * displayed them as the operator's chosen patient. On a screen whose output is
 * permanent clinical evidence, guessing is the wrong behaviour.
 *
 * Sanitisation here is deliberately TOTAL rather than a 422. This is a GET page
 * an operator lands on and types into; rejecting the request would replace one
 * unexplained blank screen with another. Anything that is not a usable
 * identifier becomes `null`, and {@see identifierSupplied()} records that the
 * operator nevertheless typed SOMETHING — which is what lets the page say
 * "Pasien tidak ditemukan" instead of pretending the field was empty.
 *
 * AUTHORIZATION IS NOT DUPLICATED HERE. The route carries
 * `permission:create_legacy_odontogram_imports` and the controller runs the
 * policy AFTER its capability check, so a disabled capability answers 404
 * rather than 403 and reveals nothing about itself. Re-deciding authorization
 * in this class would run BEFORE that capability check and invert that order.
 */
class LookupLegacyOdontogramPatientRequest extends FormRequest
{
    /** Longer than any canonical Nomor RM; anything beyond it is not an identifier. */
    private const MAX_IDENTIFIER_LENGTH = 64;

    private bool $identifierSupplied = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // prepareForValidation() has already reduced both fields to a usable
        // scalar or null. These rules are the backstop that keeps that promise
        // true if the normalisation is ever changed.
        return [
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'rm' => ['nullable', 'string', 'max:'.self::MAX_IDENTIFIER_LENGTH],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->identifierSupplied = $this->wasSupplied('patient_id') || $this->wasSupplied('rm');

        $this->merge([
            'patient_id' => $this->normalizeId($this->input('patient_id')),
            'rm' => $this->normalizeMedicalRecordNumber($this->input('rm')),
        ]);
    }

    /** The surrogate key, or null when what arrived was not one. */
    public function patientId(): ?int
    {
        $value = $this->input('patient_id');

        return is_int($value) ? $value : null;
    }

    /** The canonical Nomor RM, or null when what arrived was not usable. */
    public function medicalRecordNumber(): ?string
    {
        $value = $this->input('rm');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Did the operator type anything at all?
     *
     * This is the distinction the old code could not make, and the reason every
     * failure looked identical to a fresh page.
     */
    public function identifierSupplied(): bool
    {
        return $this->identifierSupplied;
    }

    private function wasSupplied(string $key): bool
    {
        $value = $this->input($key);

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && trim((string) $value) !== '';
    }

    /**
     * A surrogate key is a plain positive integer and nothing else.
     *
     * FILTER_VALIDATE_INT rejects what `intval()` would have guessed at:
     * `'1abc'`, `'abc'`, `['1']` and `'99999999999999999999'` (which overflows
     * silently to PHP_INT_MAX) all become null.
     */
    private function normalizeId(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = filter_var(trim((string) $value), FILTER_VALIDATE_INT);

        return ($candidate === false || $candidate < 1) ? null : $candidate;
    }

    private function normalizeMedicalRecordNumber(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $candidate = trim((string) $value);

        if ($candidate === '' || mb_strlen($candidate) > self::MAX_IDENTIFIER_LENGTH) {
            return null;
        }

        return $candidate;
    }
}
