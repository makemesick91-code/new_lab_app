<?php

namespace App\Modules\PaymentMethod\Interfaces;

use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface PaymentMethodRepositoryInterface
{
    /**
     * @param  array{search?: string|null, is_active?: bool|null, type?: string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function listActive(): Collection;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentMethod;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentMethod $paymentMethod, array $data): PaymentMethod;

    public function delete(PaymentMethod $paymentMethod): bool;
}
