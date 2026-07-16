<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Local entity ↔ SATUSEHAT/IHS identifier. Sandbox and production identifiers
 * are never mixed. Exactly one ACTIVE identifier per
 * (environment, entity_type, local_entity_type, local_entity_id). No external
 * lookup is ever performed in SATUSEHAT-1 — identifiers are entered/verified
 * administratively.
 */
class SatusehatEntityIdentifier extends Model
{
    use SoftDeletes;

    protected $table = 'mst_satusehat_entity_identifiers';

    public const ENTITY_PATIENT = 'Patient';

    public const ENTITY_PRACTITIONER = 'Practitioner';

    public const ENTITY_ORGANIZATION = 'Organization';

    public const ENTITY_LOCATION = 'Location';

    public const ENTITY_TYPES = [
        self::ENTITY_PATIENT,
        self::ENTITY_PRACTITIONER,
        self::ENTITY_ORGANIZATION,
        self::ENTITY_LOCATION,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'environment',
        'entity_type',
        'local_entity_type',
        'local_entity_id',
        'remote_identifier',
        'identifier_system',
        'status',
        'effective_from',
        'effective_to',
        'verified_at',
        'verified_by',
        'created_by',
        'source_metadata',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'local_entity_id' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'verified_at' => 'datetime',
            'verified_by' => 'integer',
            'created_by' => 'integer',
            'source_metadata' => 'array',
        ];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draf',
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_INACTIVE => 'Nonaktif',
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'warning',
            self::STATUS_ACTIVE => 'success',
            self::STATUS_INACTIVE => 'neutral',
            default => 'neutral',
        };
    }
}
