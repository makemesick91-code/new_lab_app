<?php

namespace App\Modules\QualityControl\Repositories;

use App\Modules\QualityControl\Interfaces\RemakeRequestRepositoryInterface;
use App\Modules\QualityControl\Models\RemakeRequest;
use Illuminate\Support\Collection;

class RemakeRequestRepository implements RemakeRequestRepositoryInterface
{
    public function create(array $data): RemakeRequest
    {
        return RemakeRequest::create($data);
    }

    public function findById(int $id): ?RemakeRequest
    {
        return RemakeRequest::with(['labOrder', 'qualityControl', 'requestedBy'])->find($id);
    }

    public function forLabOrder(int $labOrderId): Collection
    {
        return RemakeRequest::query()
            ->where('lab_order_id', $labOrderId)
            ->with(['requestedBy', 'qualityControl'])
            ->orderByDesc('id')
            ->get();
    }

    public function forQualityControl(int $qualityControlId): Collection
    {
        return RemakeRequest::query()
            ->where('quality_control_id', $qualityControlId)
            ->with('requestedBy')
            ->orderByDesc('id')
            ->get();
    }

    public function latestForLabOrder(int $labOrderId): ?RemakeRequest
    {
        return RemakeRequest::query()
            ->where('lab_order_id', $labOrderId)
            ->orderByDesc('id')
            ->first();
    }

    public function update(RemakeRequest $remakeRequest, array $data): RemakeRequest
    {
        $remakeRequest->update($data);

        return $remakeRequest->refresh();
    }
}
