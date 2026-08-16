<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
use App\Modules\LegacyRme\Services\LegacyRmeSourceRmNormalizer;
use App\Modules\LegacyRme\Support\LegacyRmeSourceRmFailure;
use App\Modules\Patient\Models\Patient;
use Illuminate\Console\Command;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — ask the binding gate a question without
 * uploading anything.
 *
 * WHY A COMMAND. The gate's whole value is that it REFUSES, and a refusal
 * cannot be demonstrated in production by performing the thing it refuses:
 * proving "a wrong patient is rejected" by attempting a real wrong-patient
 * import would mean manufacturing clinical data on a live system, and proving
 * "a correct patient is accepted" would mean filing a document nobody asked for.
 * So the decision is exposed read-only instead. An operator — or a post-deploy
 * verification — can see exactly what the deployed code would decide, on real
 * master data, having created nothing.
 *
 * IT IS THE SAME GATE, NOT A MODEL OF IT. It calls
 * {@see LegacyRmeSourcePatientBindingService} directly, which is the identical
 * object the upload path, the retry path and the publish revalidation call. A
 * PASS here is not a simulation of the rule; it IS the rule, answered.
 *
 * IT CREATES NOTHING AND CHANGES NOTHING. No import, no staging row, no file,
 * no queued job, no quota, no audit row, no patient. It does not need the
 * migration capability to be enabled, because it is not migrating anything —
 * which is precisely why it can be run while the feature flag is off and the
 * admitted branch set is empty, the resting state production is meant to be in.
 *
 * PII POLICY. A Nomor RM, a patient id the CALLER already supplied, a branch
 * code and a stable reason code. Never a patient name, KTP/NIK, birth date or
 * clinical detail — and, when a source RM resolves to somebody OTHER than the
 * patient being checked, never any hint of who that is. The gate itself refuses
 * to disclose that, and this command must not become the back door that does.
 *
 * EXIT CODES. 0 when the binding is accepted, 1 when it is refused. That makes
 * a deployment check scriptable: the wrong-patient probe is EXPECTED to exit 1,
 * and an exit 0 there would be the alarming outcome.
 */
class LegacyRmeSourceRmBindingCheckCommand extends Command
{
    protected $signature = 'legacy-rme:source-rm-binding-check
        {--source-rm= : The Nomor RM as printed on the source document}
        {--patient= : Id of the patient the document would be filed under}
        {--normalize-only : Show only how the value normalizes; consult no patient data}
        {--json : Emit the result as JSON}';

    protected $description = 'Check whether a source-document Nomor RM binds to a patient, read-only (creates nothing)';

    public function handle(
        LegacyRmeSourcePatientBindingService $binding,
        LegacyRmeSourceRmNormalizer $normalizer,
    ): int {
        $sourceRm = (string) ($this->option('source-rm') ?? '');

        if (trim($sourceRm) === '') {
            $this->error('--source-rm is required.');

            return self::FAILURE;
        }

        // Normalization is pure text handling and touches no patient data, so it
        // is answerable on its own. Useful for confirming that a transcription
        // style folds the way it should — and that a digit never moves.
        if ((bool) $this->option('normalize-only')) {
            return $this->emit([
                'source_rm_raw' => $sourceRm,
                'source_rm_normalized' => $normalizer->normalize($sourceRm),
                'checked' => 'NORMALIZATION_ONLY',
            ], true);
        }

        $patientId = (int) ($this->option('patient') ?? 0);

        if ($patientId <= 0) {
            $this->error('--patient is required unless --normalize-only is used.');

            return self::FAILURE;
        }

        // withTrashed(): a soft-deleted patient still owns its Nomor RM, and
        // reporting "no such patient" for one would be a lie that invites a
        // duplicate registration — the same reasoning MASTERDATA-1 applies.
        $patient = Patient::withTrashed()->find($patientId);

        if ($patient === null) {
            return $this->emit([
                'source_rm_raw' => $sourceRm,
                'patient_id' => $patientId,
                'bound' => false,
                'code' => 'SELECTED_PATIENT_NOT_FOUND',
                'message' => 'Pasien yang dipilih tidak ditemukan.',
            ], false);
        }

        // No actor is passed: this is a decision about whether the DOCUMENT
        // belongs to the PATIENT, not about who may see the branch. Mixing the
        // caller's scope in would make a scope difference read as an identity
        // failure, which is exactly the confusion this gate must not create.
        $result = $binding->bind($sourceRm, $patient);

        return $this->emit([
            'source_rm_raw' => $result->rawSourceRm,
            'source_rm_normalized' => $result->normalizedSourceRm,
            'patient_id' => $patientId,
            'bound' => $result->bound,
            'resolution' => $result->resolutionCode,
            'branch_code' => $result->branchCode,
            'code' => $result->code,
            'message' => $result->message,
            // Stated explicitly so a reader of the output never has to infer it
            // from the absence of a warning.
            'guarantees' => [
                'fuzzy_matching' => false,
                'created_anything' => false,
            ],
        ], $result->bound);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, bool $ok): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $this->line(sprintf(
                '%-24s %s',
                $key,
                is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '—'),
            ));
        }

        if ($ok) {
            $this->info('BINDING ACCEPTED — the document names this patient.');

            return self::SUCCESS;
        }

        $code = $payload['code'] ?? null;

        $this->warn(sprintf(
            'BINDING REFUSED (%s) — nothing was created.',
            is_string($code) && LegacyRmeSourceRmFailure::isKnown($code) ? $code : (string) $code,
        ));

        return self::FAILURE;
    }
}
