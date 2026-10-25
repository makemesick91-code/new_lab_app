<?php

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmePaymentSubmissionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;

class RmePaymentSubmissionRepository implements RmePaymentSubmissionRepositoryInterface
{
    public function findByIntentKey(string $intentKey): ?RmePaymentSubmission
    {
        return RmePaymentSubmission::query()->where('intent_key', $intentKey)->first();
    }

    public function create(array $attributes): RmePaymentSubmission
    {
        return RmePaymentSubmission::query()->create($attributes);
    }
}
