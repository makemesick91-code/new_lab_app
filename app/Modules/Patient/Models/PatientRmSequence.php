<?php

declare(strict_types=1);

namespace App\Modules\Patient\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — the automatic
 * Nomor RM counter of one branch and clinic year.
 *
 * Written only by {@see PatientRmNumberAllocator}
 * (under the row lock) and by `patient:rm-sequence-seed` (GREATEST, never lower).
 *
 * @property int $branch_id
 * @property int $year
 * @property int $last_number
 * @property string $seed_source
 */
class PatientRmSequence extends Model
{
    public const SOURCE_LAZY = 'lazy';

    public const SOURCE_COMMAND = 'command';

    protected $table = 'mst_patient_rm_sequences';

    protected $fillable = [
        'branch_id',
        'year',
        'last_number',
        'seed_source',
        'seeded_at',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'year' => 'integer',
        'last_number' => 'integer',
        'seeded_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
