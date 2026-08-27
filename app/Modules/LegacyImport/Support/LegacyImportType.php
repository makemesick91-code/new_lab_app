<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — the three legacy import capabilities.
 *
 * A final class of constants rather than a native enum, matching every other
 * status vocabulary in this codebase (LegacyRmeImportStatus,
 * LegacyOdontogramRecordStatus, LabWorkflowState). Consistency here is worth
 * more than the language feature: a reader who knows one of those knows this.
 *
 * These strings are persisted in `ops_legacy_import_daily_quotas.import_type`
 * and are the keys of `config('legacy_import_hub.types')`. Renaming one is a
 * data migration, not an edit.
 */
final class LegacyImportType
{
    public const LEGACY_PATIENT = 'legacy_patient';

    public const LEGACY_RME = 'legacy_rme';

    public const LEGACY_ODONTOGRAM = 'legacy_odontogram';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LEGACY_PATIENT,
            self::LEGACY_RME,
            self::LEGACY_ODONTOGRAM,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * The operator-facing label, from the registry, falling back to the raw key.
     *
     * The fallback is deliberate: a label is a convenience, and a missing one
     * must never turn a status page into an exception.
     */
    public static function label(string $type): string
    {
        $label = config('legacy_import_hub.types.'.$type.'.label');

        return is_string($label) && $label !== '' ? $label : $type;
    }
}
