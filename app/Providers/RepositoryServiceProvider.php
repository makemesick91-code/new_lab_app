<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\AccessControl\Interfaces\PermissionRepositoryInterface;
use App\Modules\AccessControl\Interfaces\RoleRepositoryInterface;
use App\Modules\AccessControl\Policies\RolePolicy;
use App\Modules\AccessControl\Repositories\PermissionRepository;
use App\Modules\AccessControl\Repositories\RoleRepository;
use App\Modules\Clinic\Interfaces\ClinicRepositoryInterface;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Clinic\Policies\ClinicPolicy;
use App\Modules\Clinic\Repositories\ClinicRepository;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Policies\DoctorPolicy;
use App\Modules\Doctor\Repositories\DoctorRepository;
use App\Modules\LabService\Interfaces\LabServiceRepositoryInterface;
use App\Modules\LabService\Models\LabService;
use App\Modules\LabService\Policies\LabServicePolicy;
use App\Modules\LabService\Repositories\LabServiceRepository;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Policies\PatientPolicy;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\Technician\Interfaces\TechnicianRepositoryInterface;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Policies\TechnicianPolicy;
use App\Modules\Technician\Repositories\TechnicianRepository;
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
        ClinicRepositoryInterface::class => ClinicRepository::class,
        DoctorRepositoryInterface::class => DoctorRepository::class,
        PatientRepositoryInterface::class => PatientRepository::class,
        LabServiceRepositoryInterface::class => LabServiceRepository::class,
        TechnicianRepositoryInterface::class => TechnicianRepository::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    private array $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        Clinic::class => ClinicPolicy::class,
        Doctor::class => DoctorPolicy::class,
        Patient::class => PatientPolicy::class,
        LabService::class => LabServicePolicy::class,
        Technician::class => TechnicianPolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $concrete) {
            $this->app->bind($interface, $concrete);
        }
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Super Admin can do everything (PRD §5).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
