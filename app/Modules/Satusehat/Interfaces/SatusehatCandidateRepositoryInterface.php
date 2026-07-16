<?php

namespace App\Modules\Satusehat\Interfaces;

use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface SatusehatCandidateRepositoryInterface
{
    /**
     * Branch-scoped, server-side filtered, paginated candidate list.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $branchIds  allowed (RME-enabled) branch ids
     */
    public function paginate(array $filters, array $branchIds, int $perPage = 20): LengthAwarePaginator;

    /**
     * Fetch a single candidate only if it belongs to an allowed branch.
     * Returns null for a forged/out-of-scope/soft-deleted id (IDOR boundary).
     *
     * @param  list<int>  $branchIds
     */
    public function findInBranches(int $id, array $branchIds): ?SatusehatCandidate;

    /**
     * Fetch the subset of the given ids that belong to allowed branches.
     * Non-member ids are silently dropped (server-side bulk IDOR boundary).
     *
     * @param  list<int>  $ids
     * @param  list<int>  $branchIds
     * @return Collection<int, SatusehatCandidate>
     */
    public function idsInBranches(array $ids, array $branchIds): Collection;
}
