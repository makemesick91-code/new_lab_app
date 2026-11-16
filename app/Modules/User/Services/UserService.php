<?php

namespace App\Modules\User\Services;

use App\Models\User;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\User\Interfaces\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for user management (PROJECT_RULES §8).
 * Role assignment (TASK-0104) is handled here, inside a DB transaction.
 */
class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserOnlineContextService $onlineContext,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->users->paginate($filters, $perPage);
    }

    public function find(int $id): ?User
    {
        return $this->users->findById($id);
    }

    public function listAll(): Collection
    {
        return $this->users->listAll();
    }

    /**
     * @param  array<string, mixed>  $data  validated user data, may contain "role"
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $role = $data['role'] ?? null;
            unset($data['role']);

            $user = $this->users->create($data);

            if ($role) {
                $user->syncRoles([$role]);
            }

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated user data, may contain "role"
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $role = $data['role'] ?? null;
            unset($data['role']);

            // Avoid overwriting the password when left blank on edit.
            if (empty($data['password'])) {
                unset($data['password']);
            }

            $user = $this->users->update($user, $data);

            if ($role !== null) {
                $user->syncRoles($role ? [$role] : []);
            }

            if (! $user->is_active) {
                $this->dropPresence($user);
            }

            return $user;
        });
    }

    public function delete(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $this->dropPresence($user);

            return $this->users->delete($user);
        });
    }

    public function activate(User $user): User
    {
        return $this->users->setActiveStatus($user, true);
    }

    public function deactivate(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = $this->users->setActiveStatus($user, false);
            $this->dropPresence($user);

            return $user;
        });
    }

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-4) — an account that stops being
     * usable stops being "online" in the same transaction. Before, only
     * EnsureAccountIsActive dropped the presence, on that account's NEXT request,
     * so for up to the presence window a deactivated doctor stayed selectable,
     * auto-assignable and room-resolvable. markOffline() is the same call the
     * logout and the next-request refusal make (it frees a doctor's room); it
     * ends no session and releases no lease, which EnsureAccountIsActive still
     * does on that account's next request (rule 200).
     */
    private function dropPresence(User $user): void
    {
        $this->onlineContext->markOffline($user);
    }
}
