<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Models;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219, owner DPT-Q1) — the
 * treating doctor's EXPLICIT answer to "was a medical procedure performed at this
 * visit?". One row per visit.
 *
 *   has_treatment  the doctor records the procedures (operational category null)
 *   no_treatment   nothing was performed => operational category KONTROL (DPT-Q1)
 *
 * The ABSENCE of a row is NOT_RECORDED — never "no treatment". The operational
 * category is a classification of the visit for operations and reporting; it is
 * NOT the stored `visit_type`, never creates or implies a parent visit, and never
 * makes anything free: Rp0 eligibility stays rule 217.
 */
class RmeTreatmentDeclaration extends Model
{
    public const TYPE_HAS_TREATMENT = 'has_treatment';

    public const TYPE_NO_TREATMENT = 'no_treatment';

    public const TYPES = [self::TYPE_HAS_TREATMENT, self::TYPE_NO_TREATMENT];

    protected $table = 'trx_rme_treatment_declarations';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'clinic_visit_id' => 'integer',
            'version' => 'integer',
            'declared_by' => 'integer',
            'declared_by_doctor_id' => 'integer',
            'declared_at' => 'datetime',
        ];
    }

    public function isNoTreatment(): bool
    {
        return $this->declaration_type === self::TYPE_NO_TREATMENT;
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }
}
