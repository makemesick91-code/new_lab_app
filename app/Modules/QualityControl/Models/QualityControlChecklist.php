<?php

namespace App\Modules\QualityControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityControlChecklist extends Model
{
    public const RESULT_PASS = 'PASS';

    public const RESULT_FAIL = 'FAIL';

    public const RESULT_NA = 'N_A';

    public const RESULTS = ['PASS', 'FAIL', 'N_A'];

    /** Default checklist items created for each QC review. */
    public const ITEMS = [
        'FIT_ACCURACY',
        'MARGIN_QUALITY',
        'OCCLUSION',
        'SHADE_MATCH',
        'CONTACT_POINT',
        'SURFACE_FINISH',
        'POLISHING',
        'CLEANLINESS',
        'PACKAGING_READINESS',
    ];

    protected $table = 'trx_lab_qc_checklists';

    protected $fillable = [
        'quality_control_id',
        'checklist_item',
        'result',
        'notes',
    ];

    public function qualityControl(): BelongsTo
    {
        return $this->belongsTo(QualityControl::class, 'quality_control_id');
    }
}
