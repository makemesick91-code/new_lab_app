<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Versioned, auditable local→SATUSEHAT code/resource mapping. Mappings are never
 * edited in place once active — a change is a new version. Exactly one ACTIVE
 * mapping per logical key (enforced by the mapping service + PG partial unique).
 */
class SatusehatCodeMapping extends Model
{
    use SoftDeletes;

    protected $table = 'mst_satusehat_code_mappings';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_DEPRECATED,
    ];

    protected $fillable = [
        'environment',
        'local_entity_type',
        'local_entity_id',
        'local_code',
        'target_resource_type',
        'target_path',
        'terminology_system',
        'target_code',
        'target_display',
        'effective_date',
        'status',
        'version',
        'reviewed_by',
        'approved_at',
        'approved_by',
        'created_by',
        'notes',
        // SATUSEHAT-3 terminology governance.
        'profile_family',
        'official_source',
        'official_source_version',
        'verified_at',
        'verified_by',
        'effective_to',
        'mapping_confidence',
    ];

    protected function casts(): array
    {
        return [
            'local_entity_id' => 'integer',
            'effective_date' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
            'reviewed_by' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'verified_at' => 'datetime',
            'verified_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * A mapping belonging to a governed profile family (e.g. "dental") may only
     * be ACTIVATED once it carries an official source citation AND a human
     * verification stamp. General (non-family) mappings keep the SATUSEHAT-1
     * behavior. Enforced in SatusehatMappingService (defense in depth + tests).
     */
    public function isProfileFamilyGoverned(): bool
    {
        return filled($this->profile_family);
    }

    public function hasOfficialProvenance(): bool
    {
        return filled($this->official_source) && $this->verified_at !== null;
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draf',
            self::STATUS_ACTIVE => 'Aktif',
            self::STATUS_DEPRECATED => 'Usang',
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'warning',
            self::STATUS_ACTIVE => 'success',
            self::STATUS_DEPRECATED => 'neutral',
            default => 'neutral',
        };
    }

    /** Logical identity key used for single-active enforcement. */
    public function activeKey(): string
    {
        return implode('|', [
            $this->environment,
            $this->local_entity_type,
            (string) ($this->local_entity_id ?? 0),
            (string) ($this->local_code ?? ''),
            $this->target_resource_type,
        ]);
    }
}
