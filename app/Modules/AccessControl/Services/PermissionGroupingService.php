<?php

namespace App\Modules\AccessControl\Services;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/**
 * Deterministic module grouping for role permission assignment UI.
 */
class PermissionGroupingService
{
    /**
     * Ordered module groups. Permissions not listed here fall into "other".
     *
     * @var array<int, array{key: string, label: string, permissions: array<int, string>}>
     */
    private const GROUP_DEFINITIONS = [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'permissions' => [
                'view dashboard',
                'view_dashboard',
            ],
        ],
        [
            'key' => 'access_control',
            'label' => 'Access Control / Pengaturan',
            'permissions' => [
                'manage users',
                'manage roles',
                'manage permissions',
            ],
        ],
        [
            'key' => 'master_data',
            'label' => 'Master Data',
            'permissions' => [
                'manage master data',
                'manage clinics',
                'manage doctors',
                'manage patients',
                'manage lab services',
                'manage technicians',
            ],
        ],
        [
            'key' => 'lab_order',
            'label' => 'Lab Order',
            'permissions' => [
                'manage_lab_orders',
                'view_lab_orders',
                'create_lab_orders',
                'update_lab_orders',
                'cancel_lab_orders',
                'manage lab orders',
                'manage assignments',
            ],
        ],
        [
            'key' => 'production',
            'label' => 'Production',
            'permissions' => [
                'manage_production',
                'view_production',
                'assign_technicians',
                'reassign_technicians',
                'start_production_work',
                'pause_production_work',
                'resume_production_work',
                'complete_production_work',
                'send_to_qc',
            ],
        ],
        [
            'key' => 'qc',
            'label' => 'QC',
            'permissions' => [
                'manage_quality_control',
                'view_quality_control',
                'start_qc',
                'pass_qc',
                'reject_qc',
                'request_remake',
                'update_qc_checklist',
                'upload_qc_evidence',
                'manage qc',
            ],
        ],
        [
            'key' => 'delivery',
            'label' => 'Delivery',
            'permissions' => [
                'manage_delivery',
                'view_delivery',
                'create_delivery',
                'assign_courier',
                'start_delivery',
                'mark_delivered',
                'complete_delivery',
                'upload_pod',
                'manage deliveries',
            ],
        ],
        [
            'key' => 'inventory',
            'label' => 'Inventory / Persediaan',
            'permissions' => [
                'view_inventory',
                'manage_inventory',
                'view_stock_opname',
                'manage_stock_opname',
                'view_inventory_batch_lot',
                'manage_inventory_batch_lot',
                'view_stock_alert',
                'manage_stock_alert',
                'view_inventory_analytics',
                'manage_inventory_analytics',
                'view_inventory_executive_dashboard',
                'view_inventory_activity_log',
                'view_stock_transfer',
                'manage_stock_transfer',
                'view_purchase_request',
                'manage_purchase_request',
                'approve_inventory_purchase_request',
                'view_purchase_order',
                'manage_purchase_order',
                'approve_inventory_purchase_order',
                'view_goods_receipt',
                'manage_goods_receipt',
            ],
        ],
        [
            'key' => 'finance',
            'label' => 'Finance',
            'permissions' => [
                'manage_invoice',
                'view_invoice',
                'create_invoice',
                'issue_invoice',
                'void_invoice',
                'manage_payment',
                'view_payment',
                'create_payment',
                'manage invoices',
                'manage payments',
            ],
        ],
        [
            'key' => 'reporting',
            'label' => 'Reporting',
            'permissions' => [
                'manage_report',
                'view_order_report',
                'view_production_report',
                'view_qc_report',
                'view_delivery_report',
                'view_invoice_report',
                'view_payment_report',
                'export_report',
                'view reports',
            ],
        ],
        [
            'key' => 'hr',
            'label' => 'HR',
            'permissions' => [],
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        'view dashboard' => 'Akses halaman dashboard utama.',
        'view_dashboard' => 'Akses dashboard operasional cabang.',
        'manage users' => 'Kelola akun pengguna sistem.',
        'manage roles' => 'Kelola role dan penugasan permission.',
        'manage permissions' => 'Lihat daftar permission sistem.',
        'manage master data' => 'Kelola data master lintas modul.',
        'manage clinics' => 'Kelola data klinik.',
        'manage doctors' => 'Kelola data dokter.',
        'manage patients' => 'Kelola data pasien.',
        'manage lab services' => 'Kelola layanan laboratorium.',
        'manage technicians' => 'Kelola data teknisi.',
        'manage_lab_orders' => 'Kelola order laboratorium (penuh).',
        'view_lab_orders' => 'Lihat order laboratorium.',
        'create_lab_orders' => 'Buat order laboratorium baru.',
        'update_lab_orders' => 'Ubah order laboratorium.',
        'cancel_lab_orders' => 'Batalkan order laboratorium.',
        'manage lab orders' => 'Kelola order (permission legacy).',
        'manage assignments' => 'Kelola penugasan produksi.',
        'manage_production' => 'Kelola alur produksi.',
        'view_production' => 'Lihat data produksi.',
        'assign_technicians' => 'Tetapkan teknisi ke order.',
        'reassign_technicians' => 'Ubah penugasan teknisi.',
        'start_production_work' => 'Mulai pekerjaan produksi.',
        'pause_production_work' => 'Jeda pekerjaan produksi.',
        'resume_production_work' => 'Lanjutkan pekerjaan produksi.',
        'complete_production_work' => 'Selesaikan pekerjaan produksi.',
        'send_to_qc' => 'Kirim order ke QC.',
        'manage_quality_control' => 'Kelola alur quality control.',
        'view_quality_control' => 'Lihat antrian dan hasil QC.',
        'start_qc' => 'Mulai review QC.',
        'pass_qc' => 'Setujui hasil QC.',
        'reject_qc' => 'Tolak hasil QC.',
        'request_remake' => 'Minta remake dari QC.',
        'update_qc_checklist' => 'Ubah checklist QC.',
        'upload_qc_evidence' => 'Unggah bukti QC.',
        'manage qc' => 'Kelola QC (permission legacy).',
        'manage_delivery' => 'Kelola pengiriman.',
        'view_delivery' => 'Lihat data pengiriman.',
        'create_delivery' => 'Buat pengiriman baru.',
        'assign_courier' => 'Tetapkan kurir.',
        'start_delivery' => 'Mulai pengiriman.',
        'mark_delivered' => 'Tandai sebagai terkirim.',
        'complete_delivery' => 'Selesaikan pengiriman.',
        'upload_pod' => 'Unggah bukti serah terima (POD).',
        'manage deliveries' => 'Kelola pengiriman (permission legacy).',
        'view_inventory' => 'Lihat data persediaan dan stok.',
        'manage_inventory' => 'Kelola persediaan, stok, dan operasi gudang.',
        'view_stock_opname' => 'Lihat sesi stok opname persediaan.',
        'manage_stock_opname' => 'Kelola stok opname persediaan.',
        'view_inventory_batch_lot' => 'Lihat batch/lot persediaan.',
        'manage_inventory_batch_lot' => 'Kelola batch/lot persediaan.',
        'view_stock_alert' => 'Lihat peringatan stok rendah.',
        'manage_stock_alert' => 'Kelola peringatan stok rendah.',
        'view_inventory_analytics' => 'Lihat analitik persediaan.',
        'manage_inventory_analytics' => 'Kelola analitik persediaan.',
        'view_inventory_executive_dashboard' => 'View inventory executive dashboard',
        'view_inventory_activity_log' => 'Lihat log aktivitas persediaan dan pengadaan.',
        'view_stock_transfer' => 'Lihat transfer stok antar lokasi.',
        'manage_stock_transfer' => 'Kelola transfer stok antar lokasi.',
        'view_purchase_request' => 'Lihat permintaan pembelian.',
        'manage_purchase_request' => 'Kelola permintaan pembelian.',
        'approve_inventory_purchase_request' => 'Setujui permintaan pembelian persediaan.',
        'view_purchase_order' => 'Lihat purchase order.',
        'manage_purchase_order' => 'Kelola purchase order.',
        'approve_inventory_purchase_order' => 'Setujui purchase order persediaan.',
        'view_goods_receipt' => 'Lihat penerimaan barang.',
        'manage_goods_receipt' => 'Kelola penerimaan barang.',
        'manage_invoice' => 'Kelola invoice.',
        'view_invoice' => 'Lihat invoice.',
        'create_invoice' => 'Buat invoice.',
        'issue_invoice' => 'Terbitkan invoice.',
        'void_invoice' => 'Void invoice.',
        'manage_payment' => 'Kelola pembayaran.',
        'view_payment' => 'Lihat pembayaran.',
        'create_payment' => 'Catat pembayaran.',
        'manage invoices' => 'Kelola invoice (permission legacy).',
        'manage payments' => 'Kelola pembayaran (permission legacy).',
        'manage_report' => 'Kelola laporan.',
        'view_order_report' => 'Lihat laporan order.',
        'view_production_report' => 'Lihat laporan produksi.',
        'view_qc_report' => 'Lihat laporan QC.',
        'view_delivery_report' => 'Lihat laporan pengiriman.',
        'view_invoice_report' => 'Lihat laporan invoice.',
        'view_payment_report' => 'Lihat laporan pembayaran.',
        'export_report' => 'Ekspor laporan.',
        'view reports' => 'Lihat laporan (permission legacy).',
    ];

    /**
     * @return array<int, array{key: string, label: string, permissions: array<int, array{name: string, description: string|null}>}>
     */
    public function group(Collection $permissions): array
    {
        /** @var Collection<string, Permission> $byName */
        $byName = $permissions->keyBy('name');
        $assigned = [];
        $groups = [];

        foreach (self::GROUP_DEFINITIONS as $definition) {
            $items = $this->resolveGroupPermissions($definition, $byName, $assigned);

            if ($definition['key'] === 'hr' && $items === []) {
                $items = $this->resolveHrPermissions($byName, $assigned);
            }

            if ($items !== []) {
                $groups[] = [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'permissions' => $items,
                ];
            }
        }

        $other = $this->resolveOtherPermissions($byName, $assigned);

        if ($other !== []) {
            $groups[] = [
                'key' => 'other',
                'label' => 'Other / Uncategorized',
                'permissions' => $other,
            ];
        }

        return $groups;
    }

    /**
     * @param  Collection<string, Permission>  $byName
     * @param  array<string, true>  $assigned
     * @return array<int, array{name: string, description: string|null}>
     */
    private function resolveGroupPermissions(array $definition, Collection $byName, array &$assigned): array
    {
        $items = [];

        foreach ($definition['permissions'] as $permissionName) {
            $permission = $byName->get($permissionName);

            if ($permission === null || isset($assigned[$permissionName])) {
                continue;
            }

            $assigned[$permissionName] = true;
            $items[] = $this->formatPermission($permissionName);
        }

        return $items;
    }

    /**
     * @param  Collection<string, Permission>  $byName
     * @param  array<string, true>  $assigned
     * @return array<int, array{name: string, description: string|null}>
     */
    private function resolveHrPermissions(Collection $byName, array &$assigned): array
    {
        $items = [];

        foreach ($byName as $permission) {
            $name = $permission->name;

            if (isset($assigned[$name]) || ! $this->isHrPermission($name)) {
                continue;
            }

            $assigned[$name] = true;
            $items[] = $this->formatPermission($name);
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * @param  Collection<string, Permission>  $byName
     * @param  array<string, true>  $assigned
     * @return array<int, array{name: string, description: string|null}>
     */
    private function resolveOtherPermissions(Collection $byName, array &$assigned): array
    {
        $items = [];

        foreach ($byName as $permission) {
            $name = $permission->name;

            if (isset($assigned[$name])) {
                continue;
            }

            $assigned[$name] = true;
            $items[] = $this->formatPermission($name);
        }

        usort($items, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $items;
    }

    /**
     * @return array{name: string, description: string|null}
     */
    private function formatPermission(string $name): array
    {
        return [
            'name' => $name,
            'description' => self::DESCRIPTIONS[$name] ?? null,
        ];
    }

    private function isHrPermission(string $name): bool
    {
        $normalized = strtolower($name);

        return str_starts_with($normalized, 'hr_')
            || str_starts_with($normalized, 'manage_hr')
            || str_starts_with($normalized, 'view_hr')
            || str_contains($normalized, '_hr_');
    }
}
