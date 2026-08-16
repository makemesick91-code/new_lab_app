<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — the outcome of asking whether the Nomor RM
 * printed on a legacy document names the patient it is about to be filed under.
 *
 * Immutable, and always explicit: either the document's asserted identity IS
 * the selected patient, or it carries the stable reason why that could not be
 * established. There is no third state and no partial credit — a binding is
 * never "probably right".
 *
 * PII POLICY. The Nomor RM is the identifier the archive already reasons about,
 * so the normalized value and a branch code may travel in an audit payload.
 * A patient NAME, KTP/NIK, birth date or any clinical detail never does — and
 * neither does the id of a patient the source RM resolved to when that patient
 * is NOT the selected one, because publishing that id would let the refusal
 * message answer "who owns this number?" for anyone who can reach the form.
 */
final class LegacyRmeSourceRmBinding
{
    private function __construct(
        public readonly bool $bound,
        public readonly ?int $patientId,
        public readonly ?string $rawSourceRm,
        public readonly ?string $normalizedSourceRm,
        /** The LegacyRmePatientResolution code identity was established on. */
        public readonly ?string $resolutionCode,
        public readonly ?string $branchCode,
        /** A LegacyRmeSourceRmFailure code; null when bound. */
        public readonly ?string $code,
        public readonly ?string $message,
    ) {}

    public static function success(
        int $patientId,
        ?string $rawSourceRm,
        string $normalizedSourceRm,
        string $resolutionCode,
        ?string $branchCode,
    ): self {
        return new self(true, $patientId, $rawSourceRm, $normalizedSourceRm, $resolutionCode, $branchCode, null, null);
    }

    /**
     * A refusal. The message defaults to the canonical explanation for the code,
     * so no caller has to invent wording — and so the non-disclosure policy in
     * {@see LegacyRmeSourceRmFailure::explain()} holds by default rather than by
     * everyone remembering it.
     */
    public static function failure(
        string $code,
        ?string $rawSourceRm = null,
        ?string $normalizedSourceRm = null,
        ?string $message = null,
        ?string $branchCode = null,
    ): self {
        return new self(
            false,
            null,
            $rawSourceRm,
            $normalizedSourceRm,
            null,
            $branchCode,
            $code,
            $message ?? LegacyRmeSourceRmFailure::explain($code),
        );
    }

    public function failed(): bool
    {
        return ! $this->bound;
    }

    /**
     * PII-free audit context.
     *
     * `source_rm_normalized` is the number the archive already stores in its own
     * column, and a branch code such as `TKM1` is an operational label. Neither
     * is a name, a KTP/NIK or a clinical detail. The raw operator transcription
     * is deliberately EXCLUDED: it is free text, and free text is exactly what
     * this domain's audit allow-list has refused to carry since 1D.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return array_filter([
            'source_rm_normalized' => $this->normalizedSourceRm,
            'source_rm_resolution' => $this->resolutionCode,
            'branch_code' => $this->branchCode,
            'rule_code' => $this->code,
        ], static fn ($value): bool => $value !== null);
    }
}
