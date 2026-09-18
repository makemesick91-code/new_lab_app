<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use App\Modules\DoctorAccess\Models\DoctorBreakGlassGrant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface DoctorBreakGlassGrantRepositoryInterface
{
    /**
     * The one query the authentication gate makes: is there an unrevoked,
     * unexpired grant for this ACCOUNT right now?
     *
     * Takes the instant explicitly so the caller's clock is the only clock,
     * and a test can drive expiry without sleeping.
     */
    public function activeForUser(int $userId, Carbon $at): ?DoctorBreakGlassGrant;

    /**
     * The same question under a row lock, for the granting transaction.
     *
     * Used to refuse a SECOND overlapping grant: stacking grants is how a
     * bounded window quietly becomes an unbounded one.
     */
    public function activeForUserForUpdate(int $userId, Carbon $at): ?DoctorBreakGlassGrant;

    public function findForUpdate(int $id): ?DoctorBreakGlassGrant;

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): DoctorBreakGlassGrant;

    /** @param  array<string, mixed>  $attributes */
    public function update(DoctorBreakGlassGrant $grant, array $attributes): DoctorBreakGlassGrant;

    /** @return LengthAwarePaginator<int, DoctorBreakGlassGrant> */
    public function paginate(int $perPage = 20): LengthAwarePaginator;
}
