<?php

namespace App\Modules\Patient\Repositories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Interfaces\PatientDocumentProvenanceRepositoryInterface;
use App\Modules\Patient\Models\PatientDocument;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientDocumentProvenanceRepository implements PatientDocumentProvenanceRepositoryInterface
{
    public function withoutCapturedBranch(): Collection
    {
        return PatientDocument::query()
            ->whereNull('captured_branch_id')
            ->with(['patient' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get();
    }

    public function visitBranchIdsCreatedBetween(int $patientId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return ClinicVisit::withTrashed()
            ->where('patient_id', $patientId)
            ->whereBetween('created_at', [$from, $to])
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function recordCapturedBranchIfUnset(int $documentId, int $branchId): bool
    {
        return DB::transaction(function () use ($documentId, $branchId): bool {
            $document = PatientDocument::query()->whereKey($documentId)->lockForUpdate()->first();

            if ($document === null || $document->captured_branch_id !== null) {
                return false;
            }

            $document->captured_branch_id = $branchId;
            $document->save();

            return true;
        });
    }
}
