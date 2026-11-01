<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * HARDENING-RME-RP0-CLINICAL-BILLING-CONSISTENCY-1 (rule 218) — the answer of
 * {@see RmeClinicalBillingConsistency}. Immutable.
 *
 * `snapshot()` is what the closure audit row records: codes, ids and counts only —
 * never a description, a note, a tooth number or anything about the patient.
 * `display()` carries the one master-data label the cashier notice names (the
 * registered treatment), and is never persisted.
 */
final class RmeClinicalBillingConsistencyResult
{
    /**
     * @param  list<string>  $signals
     * @param  array<string, int|bool|string|null>  $evidence
     * @param  array<string, string|null>  $display
     */
    private function __construct(
        private readonly string $status,
        private readonly array $signals,
        private readonly array $evidence,
        private readonly array $display,
    ) {}

    public static function notApplicable(): self
    {
        return new self(RmeClinicalBillingConsistency::STATUS_NOT_APPLICABLE, [], [], []);
    }

    /**
     * @param  list<string>  $signals
     * @param  array<string, int|bool|string|null>  $evidence
     * @param  array<string, string|null>  $display
     */
    public static function evaluated(array $signals, array $evidence, array $display = []): self
    {
        $signals = array_values(array_unique($signals));
        sort($signals);

        return new self(
            $signals === []
                ? RmeClinicalBillingConsistency::STATUS_INSUFFICIENT_STRUCTURED_EVIDENCE
                : RmeClinicalBillingConsistency::STATUS_REVIEW_REQUIRED,
            $signals,
            $evidence,
            $display,
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<string> */
    public function signals(): array
    {
        return $this->signals;
    }

    public function requiresReview(): bool
    {
        return $this->status === RmeClinicalBillingConsistency::STATUS_REVIEW_REQUIRED;
    }

    public function hasSignal(string $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /** @return array<string, int|bool|string|null> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    public function displayValue(string $key): ?string
    {
        return $this->display[$key] ?? null;
    }

    /**
     * The audit snapshot. PII-free by construction: every value is a code, an id,
     * a boolean or a count.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'version' => RmeClinicalBillingConsistency::VERSION,
            'status' => $this->status,
            'signals' => $this->signals,
            ...$this->evidence,
        ];
    }
}
