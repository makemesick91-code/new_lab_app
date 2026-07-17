<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4A/4B — master clinical diagnosis governance.
 *
 * SATUSEHAT-4B locks the operational review lifecycle:
 *   draft → under_review → approved → active → deprecated (or rejected).
 *
 * Rules enforced server-side:
 *  - New entries start as DRAFT; only ACTIVE terminology is selectable for new
 *    medical records.
 *  - Approval/activation requires an official source and clinical review
 *    (review_clinical_terminology); the reviewer can never approve an entry
 *    they created or submitted (separation of duties).
 *  - Active terminology is immutable — corrections create a new entry and
 *    deprecate the old one with an optional ACTIVE replacement pointer.
 *  - Deprecated codes remain historically readable; they are only excluded
 *    from new selection.
 *  - No code is ever guessed or auto-generated; SATUSEHAT mapping stays a
 *    separate, reviewed lifecycle in mst_satusehat_code_mappings.
 */
class ClinicalDiagnosisService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @param  array{code_system?: string, code: string, display: string, source?: ?string, source_version?: ?string, aliases?: ?string, notes?: ?string}  $data
     */
    public function create(array $data, User $actor): ClinicalDiagnosis
    {
        $codeSystem = trim((string) ($data['code_system'] ?? 'ICD-10'));
        $code = trim((string) $data['code']);

        // The synthetic rehearsal code system is reserved — its entries are
        // hidden from search and removed by the campaign reset.
        $reserved = (string) config('satusehat_data_quality.synthetic.diagnosis_code_system');
        if (strcasecmp($codeSystem, $reserved) === 0) {
            throw ValidationException::withMessages([
                'code_system' => 'Code system ini dicadangkan untuk kampanye rehearsal sintetis.',
            ]);
        }

        $this->assertCodeFormat($codeSystem, $code);

        return DB::transaction(function () use ($codeSystem, $code, $data, $actor) {
            $existing = ClinicalDiagnosis::withTrashed()
                ->where('code_system', $codeSystem)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw ValidationException::withMessages([
                    'code' => 'Kombinasi code system + kode diagnosis sudah terdaftar.',
                ]);
            }

            if ($existing !== null) {
                // Restore + refresh instead of violating the unique constraint.
                // The restored entry re-enters the lifecycle as DRAFT — it must
                // pass clinical review again before it becomes selectable.
                $existing->restore();
                $existing->update([
                    'display' => trim((string) $data['display']),
                    'status' => ClinicalDiagnosis::STATUS_DRAFT,
                    'source' => $data['source'] ?? null,
                    'source_version' => $data['source_version'] ?? null,
                    'aliases' => $data['aliases'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                return $existing;
            }

            $diagnosis = ClinicalDiagnosis::create([
                'code_system' => $codeSystem,
                'code' => $code,
                'display' => trim((string) $data['display']),
                'status' => ClinicalDiagnosis::STATUS_DRAFT,
                'source' => $data['source'] ?? null,
                'source_version' => $data['source_version'] ?? null,
                'aliases' => $data['aliases'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            return $diagnosis;
        });
    }

    public function submitForReview(ClinicalDiagnosis $diagnosis, User $actor): ClinicalDiagnosis
    {
        $this->assertStatusIn($diagnosis, [ClinicalDiagnosis::STATUS_DRAFT, ClinicalDiagnosis::STATUS_REJECTED]);

        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_UNDER_REVIEW,
            'submitted_by' => $actor->id,
            'submitted_for_review_at' => now(),
            'rejected_reason' => null,
        ]);

        $this->logTerminology($diagnosis, SatusehatAuditLog::EVENT_TERMINOLOGY_SUBMITTED, 'Terminologi diajukan untuk review klinis', $actor);

        return $diagnosis;
    }

    public function approve(ClinicalDiagnosis $diagnosis, User $actor, string $reason): ClinicalDiagnosis
    {
        $this->assertStatusIn($diagnosis, [ClinicalDiagnosis::STATUS_UNDER_REVIEW]);
        $this->assertOfficialSource($diagnosis);
        $this->assertSeparationOfDuties($diagnosis, $actor);

        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_APPROVED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'approval_reason' => mb_substr(trim($reason), 0, 500),
        ]);

        $this->logTerminology($diagnosis, SatusehatAuditLog::EVENT_TERMINOLOGY_APPROVED, 'Terminologi disetujui reviewer klinis', $actor);

        return $diagnosis;
    }

    public function reject(ClinicalDiagnosis $diagnosis, User $actor, string $reason): ClinicalDiagnosis
    {
        $this->assertStatusIn($diagnosis, [ClinicalDiagnosis::STATUS_UNDER_REVIEW]);

        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_REJECTED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'rejected_reason' => mb_substr(trim($reason), 0, 500),
        ]);

        $this->logTerminology($diagnosis, SatusehatAuditLog::EVENT_TERMINOLOGY_REJECTED, 'Terminologi ditolak reviewer klinis', $actor);

        return $diagnosis;
    }

    public function activate(ClinicalDiagnosis $diagnosis, User $actor): ClinicalDiagnosis
    {
        $this->assertStatusIn($diagnosis, [ClinicalDiagnosis::STATUS_APPROVED]);
        $this->assertOfficialSource($diagnosis);
        $this->assertSeparationOfDuties($diagnosis, $actor);

        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_ACTIVE,
        ]);

        $this->logTerminology($diagnosis, SatusehatAuditLog::EVENT_TERMINOLOGY_ACTIVATED, 'Terminologi diaktifkan untuk pemilihan klinis', $actor);

        return $diagnosis;
    }

    public function deprecate(ClinicalDiagnosis $diagnosis, User $actor, ?int $replacementId = null, ?string $reason = null): ClinicalDiagnosis
    {
        $this->assertStatusIn($diagnosis, [ClinicalDiagnosis::STATUS_ACTIVE, ClinicalDiagnosis::STATUS_APPROVED]);

        $replacement = null;
        if ($replacementId !== null) {
            $replacement = ClinicalDiagnosis::query()->find($replacementId);
            if ($replacement === null || ! $replacement->isActive() || (int) $replacement->id === (int) $diagnosis->id) {
                throw ValidationException::withMessages([
                    'replacement_diagnosis_id' => 'Terminologi pengganti harus berupa entri AKTIF yang berbeda.',
                ]);
            }
        }

        $diagnosis->update([
            'status' => ClinicalDiagnosis::STATUS_DEPRECATED,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'deprecated_by' => $actor->id,
            'deprecated_at' => now(),
            'effective_to' => now()->toDateString(),
            'replacement_diagnosis_id' => $replacement?->id,
            'notes' => $reason !== null && trim($reason) !== ''
                ? mb_substr(trim($reason), 0, 500)
                : $diagnosis->notes,
        ]);

        $this->logTerminology($diagnosis, SatusehatAuditLog::EVENT_TERMINOLOGY_DEPRECATED, 'Terminologi dinonaktifkan (deprecated)', $actor, [
            'replacement_code' => $replacement?->code,
        ]);

        return $diagnosis;
    }

    private function assertStatusIn(ClinicalDiagnosis $diagnosis, array $allowed): void
    {
        if (! in_array($diagnosis->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Transisi lifecycle terminologi tidak valid dari status "'.$diagnosis->status.'".',
            ]);
        }
    }

    private function assertOfficialSource(ClinicalDiagnosis $diagnosis): void
    {
        if (! filled($diagnosis->source)) {
            throw ValidationException::withMessages([
                'source' => 'Terminologi tanpa sumber resmi tidak dapat disetujui/diaktifkan.',
            ]);
        }
    }

    /**
     * Separation of duties: a reviewer never approves/activates an entry they
     * created or submitted themselves.
     */
    private function assertSeparationOfDuties(ClinicalDiagnosis $diagnosis, User $actor): void
    {
        $selfAuthored = ((int) $diagnosis->created_by === (int) $actor->id && $diagnosis->created_by !== null)
            || ((int) $diagnosis->submitted_by === (int) $actor->id && $diagnosis->submitted_by !== null);

        if ($selfAuthored) {
            throw ValidationException::withMessages([
                'status' => 'Pemisahan tugas: reviewer tidak dapat menyetujui terminologi yang dibuat/diajukannya sendiri.',
            ]);
        }
    }

    private function assertCodeFormat(string $codeSystem, string $code): void
    {
        $pattern = config('clinical_diagnosis_rollout.code_patterns.'.$codeSystem);
        if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $code) !== 1) {
            throw ValidationException::withMessages([
                'code' => "Format kode tidak sesuai standar {$codeSystem}.",
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logTerminology(ClinicalDiagnosis $diagnosis, string $event, string $summary, User $actor, array $context = []): void
    {
        $this->audit->log(
            'clinical_diagnosis',
            (int) $diagnosis->id,
            $event,
            $summary,
            array_filter([
                'code_system' => (string) $diagnosis->code_system,
                'code' => (string) $diagnosis->code,
                'status' => (string) $diagnosis->status,
                ...$context,
            ], fn ($v) => $v !== null),
            null,
            $actor,
        );
    }
}
