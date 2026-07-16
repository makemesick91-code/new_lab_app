<?php

namespace App\Modules\Satusehat\Support;

use App\Modules\Satusehat\Models\SatusehatCandidate;

/**
 * SATUSEHAT-4A — resolves ONE canonical operational status per candidate: the
 * single most actionable next step. Precedence: drift → hard local defects →
 * internal data/mapping gaps → workflow (open issues) → external-only
 * remaining (BLOCKED_EXTERNAL_CREDENTIAL) → READY_INTERNAL.
 *
 * External reason codes (IHS identifiers, Kemkes onboarding) NEVER produce an
 * internal remediation status — they roll up to BLOCKED_EXTERNAL_CREDENTIAL
 * honestly, without fabricating identifiers.
 */
final class SatusehatOperationalStatusResolver
{
    public const EXTERNAL_REASON_CODES = [
        'patient_ihs_missing',
        'practitioner_ihs_missing',
        'organization_ihs_missing',
        'location_ihs_missing',
    ];

    /** Reason codes that are informational and never block operationally. */
    private const IGNORED_REASON_CODES = [
        'diagnosis_not_structured', // handled via the structured-diagnosis axis below
        'preview_incomplete',
    ];

    /**
     * @param  list<string>  $reasonCodes  candidate readiness reason codes
     * @param  list<string>  $dentalReasonCodes
     * @param  bool  $hasDiagnosisMappingGap  structured diagnosis present but unmapped
     * @param  int  $openAwaitingClinicalReview  open issues awaiting clinical review
     * @param  int  $openInternalIssues  other open non-info issues
     * @param  bool  $hasInvalidDemographics  open hard patient-demographics issue
     */
    public function resolve(
        SatusehatCandidate $candidate,
        array $reasonCodes,
        array $dentalReasonCodes,
        bool $hasStructuredPrimaryDiagnosis,
        bool $hasDiagnosisMappingGap,
        int $openAwaitingClinicalReview = 0,
        int $openInternalIssues = 0,
        bool $hasInvalidDemographics = false,
    ): string {
        // 1 — drift is always first: the approval was revoked.
        if ($candidate->readiness_status === SatusehatCandidate::READINESS_SOURCE_CHANGED
            || $candidate->dental_readiness_status === SatusehatCandidate::DENTAL_SOURCE_CHANGED) {
            return 'SOURCE_CHANGED';
        }

        // 2 — hard local defects.
        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_CONFORMANCE_FAILED) {
            return 'LOCAL_CONFORMANCE_FAILED';
        }
        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_UNSUPPORTED) {
            return 'UNSUPPORTED_LOCAL_SCHEMA';
        }

        // 3 — internal data gaps, most actionable first. Invalid data beats
        // missing data: it must be corrected, never waived.
        if ($hasInvalidDemographics) {
            return 'INVALID_PATIENT_DEMOGRAPHICS';
        }
        if (in_array('patient_identity_incomplete', $reasonCodes, true)
            || in_array('relation_missing_patient', $reasonCodes, true)) {
            return 'MISSING_PATIENT_IDENTITY';
        }
        if (in_array('practitioner_missing', $reasonCodes, true)) {
            return 'MISSING_PRACTITIONER_READINESS';
        }
        if (in_array('location_missing', $reasonCodes, true)) {
            return 'MISSING_LOCATION_MAPPING';
        }
        if (! $hasStructuredPrimaryDiagnosis) {
            return 'MISSING_STRUCTURED_DIAGNOSIS';
        }
        if ($hasDiagnosisMappingGap || in_array('diagnosis_mapping_missing', $reasonCodes, true)) {
            return 'MISSING_DIAGNOSIS_MAPPING';
        }
        if (in_array('treatment_mapping_missing', $reasonCodes, true)) {
            return 'MISSING_PROCEDURE_MAPPING';
        }

        // 4 — dental axis (an absent odontogram is informational, not blocking:
        // dental Observations are simply not emitted for that visit).
        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_MAPPING_BLOCKED) {
            return 'DENTAL_MAPPING_BLOCKED';
        }
        if ($candidate->dental_readiness_status === SatusehatCandidate::DENTAL_INCOMPLETE) {
            // A visit with NO odontogram simply emits no dental Observation —
            // never an operational blocker. When an odontogram EXISTS, only its
            // internal data gaps block; dental IHS gaps are external.
            $internalDentalGaps = collect($dentalReasonCodes)
                ->reject(fn (string $c) => $c === 'dental_odontogram_missing' || str_ends_with($c, '_ihs_missing'));
            if (! in_array('dental_odontogram_missing', $dentalReasonCodes, true) && $internalDentalGaps->isNotEmpty()) {
                return 'DENTAL_INCOMPLETE';
            }
        }

        // 5 — workflow states from the issue workspace.
        if ($openAwaitingClinicalReview > 0) {
            return 'AWAITING_CLINICAL_REVIEW';
        }

        // 6 — anything else internal still pending?
        $internalRemaining = collect($reasonCodes)
            ->reject(fn (string $code) => in_array($code, self::EXTERNAL_REASON_CODES, true))
            ->reject(fn (string $code) => in_array($code, self::IGNORED_REASON_CODES, true));
        if ($internalRemaining->isNotEmpty() || $openInternalIssues > 0) {
            return 'AWAITING_OPERATOR_REMEDIATION';
        }

        // 7 — only external items remain → honest external block.
        $externalRemaining = collect($reasonCodes)
            ->filter(fn (string $code) => in_array($code, self::EXTERNAL_REASON_CODES, true));
        if ($externalRemaining->isNotEmpty()) {
            return 'BLOCKED_EXTERNAL_CREDENTIAL';
        }

        return 'READY_INTERNAL';
    }
}
