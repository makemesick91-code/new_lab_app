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
use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Policies\DeliveryPolicy;
use App\Modules\Delivery\Repositories\DeliveryRepository;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Policies\DoctorPolicy;
use App\Modules\Doctor\Repositories\DoctorRepository;
use App\Modules\Invoice\Interfaces\InvoiceRepositoryInterface;
use App\Modules\Invoice\Interfaces\PaymentRepositoryInterface;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\Invoice\Policies\InvoicePolicy;
use App\Modules\Invoice\Policies\PaymentPolicy;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Invoice\Repositories\PaymentRepository;
use App\Modules\LabOrder\Interfaces\AttachmentRepositoryInterface;
use App\Modules\LabOrder\Interfaces\AuditLogRepositoryInterface;
use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use App\Modules\LabOrder\Interfaces\StatusLogRepositoryInterface;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Policies\AttachmentPolicy;
use App\Modules\LabOrder\Policies\LabOrderPolicy;
use App\Modules\LabOrder\Repositories\AttachmentRepository;
use App\Modules\LabOrder\Repositories\AuditLogRepository;
use App\Modules\LabOrder\Repositories\LabOrderRepository;
use App\Modules\LabOrder\Repositories\StatusLogRepository;
use App\Modules\LabService\Interfaces\LabServiceRepositoryInterface;
use App\Modules\LabService\Models\LabService;
use App\Modules\LabService\Policies\LabServicePolicy;
use App\Modules\LabService\Repositories\LabServiceRepository;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Policies\PatientPolicy;
use App\Modules\Patient\Repositories\PatientRepository;
use App\Modules\Production\Interfaces\AssignmentRepositoryInterface;
use App\Modules\Production\Interfaces\ProductionStepRepositoryInterface;
use App\Modules\Production\Interfaces\WorkLogRepositoryInterface;
use App\Modules\Production\Policies\AssignmentPolicy;
use App\Modules\Production\Policies\ProductionPolicy;
use App\Modules\Production\Policies\ProductionStepPolicy;
use App\Modules\Production\Policies\WorkLogPolicy;
use App\Modules\Production\Repositories\AssignmentRepository;
use App\Modules\Production\Repositories\ProductionStepRepository;
use App\Modules\Production\Repositories\WorkLogRepository;
use App\Modules\QualityControl\Interfaces\ChecklistRepositoryInterface;
use App\Modules\QualityControl\Interfaces\QualityControlRepositoryInterface;
use App\Modules\QualityControl\Interfaces\RemakeRequestRepositoryInterface;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Policies\ChecklistPolicy;
use App\Modules\QualityControl\Policies\QualityControlPolicy;
use App\Modules\QualityControl\Policies\RemakePolicy;
use App\Modules\QualityControl\Repositories\ChecklistRepository;
use App\Modules\QualityControl\Repositories\QualityControlRepository;
use App\Modules\QualityControl\Repositories\RemakeRequestRepository;
use App\Modules\Reporting\Interfaces\ReportingRepositoryInterface;
use App\Modules\Reporting\Policies\ReportingPolicy;
use App\Modules\Reporting\Repositories\ReportingRepository;
use App\Modules\Technician\Interfaces\TechnicianRepositoryInterface;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Policies\TechnicianPolicy;
use App\Modules\Technician\Repositories\TechnicianRepository;
use App\Modules\User\Interfaces\UserRepositoryInterface;
use App\Modules\User\Policies\UserPolicy;
use App\Modules\User\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        LabOrderRepositoryInterface::class => LabOrderRepository::class,
        AttachmentRepositoryInterface::class => AttachmentRepository::class,
        AuditLogRepositoryInterface::class => AuditLogRepository::class,
        StatusLogRepositoryInterface::class => StatusLogRepository::class,
        AssignmentRepositoryInterface::class => AssignmentRepository::class,
        WorkLogRepositoryInterface::class => WorkLogRepository::class,
        ProductionStepRepositoryInterface::class => ProductionStepRepository::class,
        QualityControlRepositoryInterface::class => QualityControlRepository::class,
        ChecklistRepositoryInterface::class => ChecklistRepository::class,
        RemakeRequestRepositoryInterface::class => RemakeRequestRepository::class,
        DeliveryRepositoryInterface::class => DeliveryRepository::class,
        InvoiceRepositoryInterface::class => InvoiceRepository::class,
        PaymentRepositoryInterface::class => PaymentRepository::class,
        ReportingRepositoryInterface::class => ReportingRepository::class,
    ];

    /**
     * Production workflow gate abilities (LabOrder already has LabOrderPolicy,
     * so production checks are registered as named gates instead of model policies).
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private array $gates = [
        'production.viewAny' => [ProductionPolicy::class, 'viewAny'],
        'production.view' => [ProductionPolicy::class, 'view'],
        'production.start' => [ProductionPolicy::class, 'start'],
        'production.pause' => [ProductionPolicy::class, 'pause'],
        'production.resume' => [ProductionPolicy::class, 'resume'],
        'production.complete' => [ProductionPolicy::class, 'complete'],
        'production.sendToQc' => [ProductionPolicy::class, 'sendToQc'],
        'production.assign' => [AssignmentPolicy::class, 'assign'],
        'production.reassign' => [AssignmentPolicy::class, 'reassign'],
        'production.cancelAssignment' => [AssignmentPolicy::class, 'cancel'],
        'production.steps.update' => [ProductionStepPolicy::class, 'update'],
        'production.worklogs.view' => [WorkLogPolicy::class, 'view'],
        // Sprint 5 — Quality Control
        'qc.viewAny' => [QualityControlPolicy::class, 'viewAny'],
        'qc.view' => [QualityControlPolicy::class, 'view'],
        'qc.start' => [QualityControlPolicy::class, 'start'],
        'qc.pass' => [QualityControlPolicy::class, 'pass'],
        'qc.reject' => [QualityControlPolicy::class, 'reject'],
        'qc.uploadEvidence' => [QualityControlPolicy::class, 'uploadEvidence'],
        'qc.checklists.update' => [ChecklistPolicy::class, 'updateChecklist'],
        'qc.requestRemake' => [RemakePolicy::class, 'requestRemake'],
        // Sprint 6 - Delivery & POD
        'delivery.viewAny' => [DeliveryPolicy::class, 'viewAny'],
        'delivery.view' => [DeliveryPolicy::class, 'view'],
        'delivery.create' => [DeliveryPolicy::class, 'create'],
        'delivery.assignCourier' => [DeliveryPolicy::class, 'assignCourier'],
        'delivery.startDelivery' => [DeliveryPolicy::class, 'startDelivery'],
        'delivery.markDelivered' => [DeliveryPolicy::class, 'markDelivered'],
        'delivery.completeDelivery' => [DeliveryPolicy::class, 'completeDelivery'],
        'delivery.uploadPod' => [DeliveryPolicy::class, 'uploadPod'],
        // Sprint 8 - Reporting & Dashboard
        'reporting.dashboard' => [ReportingPolicy::class, 'viewDashboard'],
        'reporting.orders' => [ReportingPolicy::class, 'viewOrderReport'],
        'reporting.production' => [ReportingPolicy::class, 'viewProductionReport'],
        'reporting.qc' => [ReportingPolicy::class, 'viewQcReport'],
        'reporting.delivery' => [ReportingPolicy::class, 'viewDeliveryReport'],
        'reporting.invoices' => [ReportingPolicy::class, 'viewInvoiceReport'],
        'reporting.payments' => [ReportingPolicy::class, 'viewPaymentReport'],
        'reporting.export' => [ReportingPolicy::class, 'exportReport'],
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
        LabOrder::class => LabOrderPolicy::class,
        Attachment::class => AttachmentPolicy::class,
        Delivery::class => DeliveryPolicy::class,
        Invoice::class => InvoicePolicy::class,
        Payment::class => PaymentPolicy::class,
    ];

    public function register(): void
    {
        foreach ($this->repositories as $interface => $concrete) {
            $this->app->bind($interface, $concrete);
        }
    }

    public function boot(): void
    {
        // Polymorphic alias so sys_attachments/sys_audit_logs store entity_type
        // as the table name (e.g. "trx_lab_orders") instead of the FQCN.
        // Non-enforcing: other morphs (e.g. Spatie roles) keep their defaults.
        Relation::morphMap([
            LabOrder::ENTITY_TYPE => LabOrder::class,
            QualityControl::ENTITY_TYPE => QualityControl::class,
            Delivery::ENTITY_TYPE => Delivery::class,
            Invoice::ENTITY_TYPE => Invoice::class,
            Payment::ENTITY_TYPE => Payment::class,
        ]);

        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        foreach ($this->gates as $ability => $callback) {
            Gate::define($ability, $callback);
        }

        // Super Admin can do everything (PRD §5).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('Super Admin') ? true : null;
        });
    }
}
