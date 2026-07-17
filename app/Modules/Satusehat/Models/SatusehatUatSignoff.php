<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — role-based human UAT sign-off.
 *
 * A genuine operator decision. Never fabricated; operator_name/role identify a
 * real human accountable for the sign-off.
 */
class SatusehatUatSignoff extends Model
{
    protected $table = 'trx_satusehat_uat_signoffs';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'uat_run_id',
        'role',
        'signed_by_user_id',
        'operator_name',
        'operator_role',
        'decision',
        'notes',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'uat_run_id' => 'integer',
            'signed_by_user_id' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SatusehatUatRun::class, 'uat_run_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }
}
