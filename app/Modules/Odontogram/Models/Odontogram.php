<?php

namespace App\Modules\Odontogram\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Database\Factories\OdontogramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Odontogram extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    /**
     * Daengtisia DMF-T mapping (Hotfix Sprint 60.3 — PDF template parity).
     *
     * Per the official Daengtisia odontogram legend: D = Decay, M = Missing,
     * F = Filling. The per-tooth `status` stored in tooth_map_payload is the
     * single source of truth; restorative statuses (filling/crown/root_treated)
     * count toward the F component. `normal` and unset teeth are excluded.
     */
    public const DMF_MAP = [
        'caries' => 'D',
        'missing' => 'M',
        'filling' => 'F',
        'crown' => 'F',
        'root_treated' => 'F',
    ];

    protected $table = 'trx_odontograms';

    protected $fillable = [
        'clinic_visit_id',
        'branch_id',
        'medical_record_id',
        'status',
        'summary_notes',
        'additional_conditions',
        'tooth_map_payload',
        'finalized_at',
        'finalized_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tooth_map_payload' => 'array',
        'finalized_at' => 'datetime',
    ];

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    /**
     * Does this odontogram carry actual clinical evidence?
     *
     * An odontogram counts as clinical evidence only once a tooth has really
     * been recorded: an empty draft is a persistence placeholder, not a
     * finding. Content is the whole test — a finalized row that charts nothing
     * is still nothing, and a draft that charts a tooth is still evidence.
     *
     * THE ONE DEFINITION. It was previously private to `OdontogramService`,
     * where only Patient Odontogram History could reach it, while the legacy
     * odontogram archive's native-reference cutoff counted bare row existence.
     * The two answers disagreed: a row the doctor is never shown as history
     * could still bound — and gate — a patient's archive.
     * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 promoted it here, beside
     * `dmftCounts()`, which reads the same `tooth_map_payload['teeth']`. Every
     * consumer goes through this method; nobody re-derives it.
     *
     * SHAPE. `teeth` is an OBJECT keyed by FDI number ("11", "12", …), not a
     * list — verified against the pilot database. `tooth_map_payload` is cast
     * to `array`, so both the object and the legacy empty-list shape arrive
     * here as PHP arrays and an empty one is falsy either way.
     *
     * A charted tooth counts REGARDLESS of its status, `normal` included. The
     * asymmetry is deliberate: treating real content as empty would erase a
     * chronological boundary and let an overlapping document into the archive,
     * whereas treating a sparse chart as content only ever keeps a boundary.
     * When in doubt this errs towards keeping the boundary.
     */
    public function hasRecordedTeeth(): bool
    {
        $payload = $this->tooth_map_payload;

        if (! is_array($payload)) {
            return false;
        }

        $teeth = $payload['teeth'] ?? null;

        return is_array($teeth) && $teeth !== [];
    }

    /**
     * Compute the Daengtisia DMF-T tally (Jumlah D / M / F / DMF-T) from the
     * saved table data only. Source of truth = tooth_map_payload.teeth[*].status.
     *
     * @return array{D:int, M:int, F:int, DMFT:int}
     */
    public function dmftCounts(): array
    {
        $teeth = $this->tooth_map_payload['teeth'] ?? [];
        $counts = ['D' => 0, 'M' => 0, 'F' => 0];

        if (is_array($teeth)) {
            foreach ($teeth as $tooth) {
                $status = is_array($tooth) ? ($tooth['status'] ?? null) : null;
                $component = $status ? (self::DMF_MAP[$status] ?? null) : null;
                if ($component !== null) {
                    $counts[$component]++;
                }
            }
        }

        $counts['DMFT'] = $counts['D'] + $counts['M'] + $counts['F'];

        return $counts;
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    protected static function newFactory(): OdontogramFactory
    {
        return OdontogramFactory::new();
    }
}
