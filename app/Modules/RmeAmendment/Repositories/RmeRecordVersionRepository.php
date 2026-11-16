<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Repositories;

use App\Modules\RmeAmendment\Interfaces\RmeRecordVersionRepositoryInterface;
use App\Modules\RmeAmendment\Models\RmeRecordVersion;
use Illuminate\Support\Collection;

class RmeRecordVersionRepository implements RmeRecordVersionRepositoryInterface
{
    public function create(array $data): RmeRecordVersion
    {
        return RmeRecordVersion::query()->create($data);
    }

    public function latestFor(string $subjectType, int $subjectId): ?RmeRecordVersion
    {
        return RmeRecordVersion::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('version')
            ->first();
    }

    public function listFor(string $subjectType, int $subjectId): Collection
    {
        return RmeRecordVersion::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('version')
            ->get();
    }

    public function findVersion(string $subjectType, int $subjectId, int $version): ?RmeRecordVersion
    {
        return RmeRecordVersion::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('version', $version)
            ->first();
    }

    public function countKind(string $subjectType, int $subjectId, string $kind): int
    {
        return RmeRecordVersion::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('kind', $kind)
            ->count();
    }
}
