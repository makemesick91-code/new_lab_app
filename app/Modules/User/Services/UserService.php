<?php

namespace App\Modules\User\Services;

use App\Models\User;
use App\Modules\User\Interfaces\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for user management (PROJECT_RULES §8).
 * Role assignment (TASK-0104) is handled here, inside a DB transaction.
 */
class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        return $this->users->paginate($filters, $perPage);
    }

    public function find(int $id): ?User
    {
        return $this->users->findById($id);
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

            return $user;
        });
    }

    public function delete(User $user): bool
    {
        return DB::transaction(fn () => $this->users->delete($user));
    }

    public function activate(User $user): User
    {
        return $this->users->setActiveStatus($user, true);
    }

    public function deactivate(User $user): User
    {
        return $this->users->setActiveStatus($user, false);
    }
}
