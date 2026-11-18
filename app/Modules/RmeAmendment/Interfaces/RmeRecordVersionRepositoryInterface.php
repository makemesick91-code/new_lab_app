<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Interfaces;

use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use Illuminate\Support\Collection;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §5) — persistence of the
 * immutable record versions. There is deliberately no update and no delete.
 */
interface RmeRecordVersionRepositoryInterface
{
    /** @param array<string, mixed> $data */
    public function create(array $data): RmeRecordVersion;

    public function latestFor(string $subjectType, int $subjectId): ?RmeRecordVersion;

    /** @return Collection<int, RmeRecordVersion> ordered by version */
    public function listFor(string $subjectType, int $subjectId): Collection;

    public function findVersion(string $subjectType, int $subjectId, int $version): ?RmeRecordVersion;

    public function countKind(string $subjectType, int $subjectId, string $kind): int;
}
