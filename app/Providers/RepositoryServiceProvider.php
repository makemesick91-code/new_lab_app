<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\AccessControl\Interfaces\PermissionRepositoryInterface;
use App\Modules\AccessControl\Interfaces\RoleRepositoryInterface;
use App\Modules\AccessControl\Policies\RolePolicy;
use App\Modules\AccessControl\Repositories\PermissionRepository;
use App\Modules\AccessControl\Repositories\RoleRepository;
use App\Modules\User\Interfaces\UserRepositoryInterface;
use App\Modules\User\Policies\UserPolicy;
use App\Modules\User\Repositories\UserRepository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

/**
 * Wires the modular monolith together:
 *  - binds repository interfaces to concrete implementations (ADR-004),
 *  - registers module policies,
 *  - grants Super Admin an implicit bypass for every ability.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private array $repositories = [
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        PermissionRepositoryInterface::class => PermissionRepository::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $concrete) {
            $this->app->bind($interface, $concrete);
        }
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        // Super Admin can do everything (PRD §5).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
