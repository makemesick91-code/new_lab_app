<?php

namespace Database\Seeders;

use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Illuminate\Database\Seeder;

/**
 * Sprint 19 Phase 5 — default WA reminder templates (global master data).
 * Idempotent: updateOrCreate keyed by code, never truncates or deletes.
 */
class WaReminderTemplateSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public const TEMPLATES = [
        [
            'code' => 'APPOINTMENT_REMINDER',
            'name' => 'Reminder Janji Pasien',
            'trigger_type' => WaReminderTemplate::TRIGGER_APPOINTMENT_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Halo {{patient_name}}, kami mengingatkan jadwal kunjungan Anda di {{clinic_name}} pada {{appointment_datetime}}. Terima kasih.',
            'available_variables' => ['patient_name', 'clinic_name', 'appointment_datetime'],
            'sort_order' => 10,
        ],
        [
            'code' => 'PAYMENT_REMINDER',
            'name' => 'Reminder Pembayaran',
            'trigger_type' => WaReminderTemplate::TRIGGER_PAYMENT_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Halo {{patient_name}}, tagihan Anda sebesar {{amount_due}} untuk {{service_name}} masih menunggu pembayaran. Terima kasih.',
            'available_variables' => ['patient_name', 'amount_due', 'service_name'],
            'sort_order' => 20,
        ],
        [
            'code' => 'INSTALLMENT_REMINDER',
            'name' => 'Reminder Cicilan',
            'trigger_type' => WaReminderTemplate::TRIGGER_INSTALLMENT_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Halo {{patient_name}}, cicilan ke-{{installment_number}} sebesar {{installment_amount}} jatuh tempo pada {{due_date}}. Terima kasih.',
            'available_variables' => ['patient_name', 'installment_number', 'installment_amount', 'due_date'],
            'sort_order' => 30,
        ],
        [
            'code' => 'FOLLOW_UP_REMINDER',
            'name' => 'Reminder Kontrol Ulang',
            'trigger_type' => WaReminderTemplate::TRIGGER_FOLLOW_UP_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Halo {{patient_name}}, saatnya kontrol ulang di {{clinic_name}}. Silakan hubungi admin untuk konfirmasi jadwal.',
            'available_variables' => ['patient_name', 'clinic_name'],
            'sort_order' => 40,
        ],
        [
            'code' => 'LAB_CASE_READY',
            'name' => 'Reminder Hasil Lab Selesai',
            'trigger_type' => WaReminderTemplate::TRIGGER_LAB_CASE_READY,
            'audience_type' => WaReminderTemplate::AUDIENCE_CLINIC,
            'message_body' => 'Halo {{clinic_name}}, pekerjaan lab untuk pasien {{patient_name}} sudah selesai dan siap diproses lebih lanjut.',
            'available_variables' => ['clinic_name', 'patient_name'],
            'sort_order' => 50,
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $template) {
            WaReminderTemplate::updateOrCreate(
                ['code' => $template['code']],
                [
                    'name' => $template['name'],
                    'trigger_type' => $template['trigger_type'],
                    'audience_type' => $template['audience_type'],
                    'message_body' => $template['message_body'],
                    'available_variables' => $template['available_variables'],
                    'description' => null,
                    'sort_order' => $template['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }
}
