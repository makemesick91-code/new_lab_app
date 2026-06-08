<?php

namespace App\Modules\PaymentMethod\Services;

use App\Modules\PaymentMethod\Interfaces\PaymentMethodRepositoryInterface;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentMethodService
{
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $paymentMethods,
    ) {}

    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->paymentMethods->paginate($filters, $perPage);
    }

    public function listActive(): Collection
    {
        return $this->paymentMethods->listActive();
    }

    public function create(array $data): PaymentMethod
    {
        return DB::transaction(fn () => $this->paymentMethods->create($data));
    }

    public function update(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        return DB::transaction(fn () => $this->paymentMethods->update($paymentMethod, $data));
    }

    public function delete(PaymentMethod $paymentMethod): bool
    {
        return DB::transaction(fn () => $this->paymentMethods->delete($paymentMethod));
    }
}
