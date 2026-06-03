<?php

namespace App\Modules\Delivery\Interfaces;

use App\Modules\Delivery\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface DeliveryRepositoryInterface
{
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Delivery;

    public function create(array $data): Delivery;

    public function update(Delivery $delivery, array $data): Delivery;

    public function assignCourier(Delivery $delivery, int $courierId): Delivery;

    public function reassignCourier(Delivery $delivery, int $courierId): Delivery;

    public function startDelivery(Delivery $delivery, array $data = []): Delivery;

    public function markDelivered(Delivery $delivery, array $data): Delivery;

    public function completeDelivery(Delivery $delivery, array $data = []): Delivery;

    public function latestDeliveryNumberForYear(string $year): ?string;
}
