<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §5) — one immutable version
 * of a final sheet or of a finalized odontogram.
 *
 * Version 1 is written by finalize (`final`) or, for a record finalized before
 * rule 240, by the first amendment (`baseline`); later versions are amendments.
 * The snapshot is hash-chained to the previous version (RmeRecordVersionService).
 * A version is never updated or deleted: this model refuses, and on PostgreSQL a
 * trigger refuses a raw UPDATE, DELETE or TRUNCATE.
 *
 * `snapshot` is stored as the canonical JSON text the hash covers, verbatim
 * (review MEDIUM-C): it is set only from that text (snapshotJson() reads it back
 * unchanged, for the hash) and reads as its decoded array. It has no `array`
 * cast, which would re-encode it with other flags (5.0 stored as 5).
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $version
 * @property string $kind
 * @property int $snapshot_schema_version
 * @property array<string, mixed> $snapshot
 * @property string|null $previous_hash
 * @property string $hash
 * @property int|null $created_by
 */
class RmeRecordVersion extends Model
{
    public const SUBJECT_MEDICAL_RECORD = 'medical_record';

    public const SUBJECT_ODONTOGRAM = 'odontogram';

    /** Version 1 written by finalize. */
    public const KIND_FINAL = 'final';

    /** Version 1 written lazily for a record finalized before rule 240. */
    public const KIND_BASELINE = 'baseline';

    /** Version 2+ written by an applied amendment (M3.3 PR5). */
    public const KIND_AMENDMENT = 'amendment';

    public const UPDATED_AT = null;

    protected $table = 'trx_rme_record_versions';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'version',
        'kind',
        'snapshot_schema_version',
        'snapshot',
        'previous_hash',
        'hash',
        'created_by',
        'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeRecordVersion $version): void {
            throw new LogicException(sprintf('RME record version #%d is immutable.', (int) $version->getKey()));
        });

        static::deleting(function (RmeRecordVersion $version): void {
            throw new LogicException(sprintf('RME record version #%d can never be deleted.', (int) $version->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'version' => 'integer',
            'snapshot_schema_version' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The decoded snapshot on read; on write only the canonical JSON text the
     * hash was computed over (RmeRecordVersionService), stored as given.
     */
    protected function snapshot(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?array => $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            set: function (mixed $value): string {
                if (! is_string($value)) {
                    throw new LogicException('A record version snapshot is stored only as the canonical JSON text its hash covers.');
                }

                return $value;
            },
        );
    }

    /** The snapshot exactly as stored: the text the hash covers. */
    public function snapshotJson(): string
    {
        return (string) $this->getAttributes()['snapshot'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
