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
     * @var array<int, array{key: string, label: string, description: string, permissions: array<int, string>}>
     */
    private const GROUP_DEFINITIONS = [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'description' => 'Akses dashboard, ringkasan, dan tampilan utama operasional.',
            'permissions' => [
                'view dashboard',
                'view_dashboard',
            ],
        ],
        [
            'key' => 'access_control',
            'label' => 'Access Control / Pengaturan',
            'description' => 'Akses pengelolaan user, role, dan permission.',
            'permissions' => [
                'manage users',
                'manage roles',
                'manage permissions',
            ],
        ],
        [
            'key' => 'master_data',
            'label' => 'Master Data',
            'description' => 'Akses pengelolaan master data umum.',
            'permissions' => [
                'manage master data',
                'manage clinics',
                'manage doctors',
                'manage_doctor_account_links',
                'manage patients',
                'manage lab services',
            ],
        ],
        [
            'key' => 'rme',
            'label' => 'RME / Rekam Medis',
            'description' => 'Akses workflow kunjungan klinik, rekam medis, billing RME, dan laporan RME.',
            'permissions' => [
                'view_clinic_visits',
                'manage_clinic_visits',
                'manage_rme_billing',
                'view_rme_patient_reports',
                'view_rme_payment_reports',
                // LEGACY-RME-PDF-1A — legacy (historical) RME PDF archive
                'view_legacy_rme_imports',
                'create_legacy_rme_imports',
                'review_legacy_rme_imports',
                'publish_legacy_rme_imports',
                'void_legacy_rme_imports',
                // LEGACY-RME-PDF-ROLL-4 — migration operations control plane
                'view_legacy_rme_migration_operations',
                'manage_legacy_rme_migration_operations',
                'approve_legacy_rme_migration_wave',
                // FIX-04b — legacy (historical) odontogram chart archive
                'view_legacy_odontogram_imports',
                'create_legacy_odontogram_imports',
                'review_legacy_odontogram_imports',
                'publish_legacy_odontogram_imports',
                'void_legacy_odontogram_records',
                'view_legacy_odontogram_archive',
            ],
        ],
        [
            'key' => 'lab_order',
            'label' => 'Lab Order',
            'description' => 'Akses workflow order lab, produksi, QC, delivery, dan POD.',
            'permissions' => [
                'manage_lab_orders',
                'view_lab_orders',
                'create_lab_orders',
                'update_lab_orders',
                'cancel_lab_orders',
                'manage lab orders',
            ],
        ],
        [
            'key' => 'technician_assignment',
            'label' => 'Technician / Assignment',
            'description' => 'Akses penugasan teknisi, assignment pekerjaan, dan pengelolaan teknisi.',
            'permissions' => [
                'assign_technicians',
                'reassign_technicians',
                'manage technicians',
                'manage assignments',
            ],
        ],
        [
            'key' => 'production',
            'label' => 'Production',
            'description' => 'Akses eksekusi pekerjaan produksi.',
            'permissions' => [
                'manage_production',
                'view_production',
                'start_production_work',
                'pause_production_work',
                'resume_production_work',
                'complete_production_work',
                'send_to_qc',
            ],
        ],
        [
            'key' => 'qc',
            'label' => 'Quality Control',
            'description' => 'Akses pemeriksaan kualitas, remake request, dan kontrol hasil produksi.',
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
            'label' => 'Delivery / Pengiriman',
            'description' => 'Akses pengaturan kurir, proses pengiriman, POD, dan status delivery.',
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
            'description' => 'Akses pengelolaan produk, supplier, lokasi, stok, dan pergerakan persediaan.',
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
                'view_inventory_cross_branch_analytics',
                'view_inventory_activity_log',
                'view_stock_transfer',
                'manage_stock_transfer',
                'approve_inventory_purchase_request',
                'view_purchase_order',
                'manage_purchase_order',
                'approve_inventory_purchase_order',
                'view_goods_receipt',
                'manage_goods_receipt',
            ],
        ],
        [
            'key' => 'purchase',
            'label' => 'Purchase / Procurement',
            'description' => 'Akses permintaan pembelian, review procurement, dan alur pengadaan.',
            'permissions' => [
                'view_purchase_request',
                'manage_purchase_request',
            ],
        ],
        [
            'key' => 'branch_master_data',
            'label' => 'Branch Master Data',
            'description' => 'Akses master data cabang dan konfigurasi operasional cabang.',
            'permissions' => [
                'view_branch_master_data',
                'manage_branch_master_data',
            ],
        ],
        [
            'key' => 'finance',
            'label' => 'Finance',
            'description' => 'Akses pembayaran, invoice, kasir, piutang, dan laporan keuangan.',
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
            'description' => 'Akses dashboard laporan, report, export, dan print.',
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
            'description' => 'Akses pengelolaan data dan proses sumber daya manusia.',
            'permissions' => [],
        ],
    ];

    private const OTHER_DESCRIPTION = 'Permission yang belum masuk klasifikasi modul utama. Review sebelum diberikan ke role.';

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
        'manage_doctor_account_links' => 'Hubungkan akun login ke data dokter (menentukan akses kinerja dan pendapatan dokter).',
        'manage patients' => 'Kelola data pasien.',
        'manage lab services' => 'Kelola layanan laboratorium.',
        'manage technicians' => 'Kelola data teknisi.',
        'view_clinic_visits' => 'Lihat antrian kunjungan klinik (RME).',
        'manage_clinic_visits' => 'Kelola kunjungan klinik dan alur RME.',
        'manage_rme_billing' => 'Kelola penagihan/kasir RME (full payment).',
        'view_rme_patient_reports' => 'Lihat laporan pasien RME.',
        'view_rme_payment_reports' => 'Lihat laporan pembayaran RME.',
        'view_legacy_rme_migration_operations' => 'Lihat operasi migrasi arsip RME lama (gelombang, kuota, backlog, rekonsiliasi).',
        'manage_legacy_rme_migration_operations' => 'Kelola gelombang migrasi arsip RME lama: cabang, operator, kuota, jeda dan penyelesaian.',
        'approve_legacy_rme_migration_wave' => 'Setujui gelombang migrasi arsip RME lama.',
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
        'view_inventory_cross_branch_analytics' => 'Lihat perbandingan analitik persediaan lintas cabang.',
        'view_inventory_activity_log' => 'Lihat log aktivitas persediaan dan pengadaan.',
        'view_stock_transfer' => 'Lihat transfer stok antar lokasi.',
        'manage_stock_transfer' => 'Kelola transfer stok antar lokasi.',
        'view_purchase_request' => 'Lihat permintaan pembelian.',
        'manage_purchase_request' => 'Kelola permintaan pembelian.',
        'view_branch_master_data' => 'Lihat master data cabang.',
        'manage_branch_master_data' => 'Kelola master data dan konfigurasi cabang.',
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
     * @return array<int, array{key: string, label: string, description: string, permissions: array<int, array{name: string, description: string|null}>}>
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
                    'description' => $definition['description'],
                    'permissions' => $items,
                ];
            }
        }

        $other = $this->resolveOtherPermissions($byName, $assigned);

        if ($other !== []) {
            $groups[] = [
                'key' => 'other',
                'label' => 'Other / Uncategorized',
                'description' => self::OTHER_DESCRIPTION,
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
