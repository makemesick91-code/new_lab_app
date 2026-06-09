<?php

namespace App\Modules\WaReminderTemplate\Models;

use Database\Factories\WaReminderTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaReminderTemplate extends Model
{
    use HasFactory, SoftDeletes;

    public const TRIGGER_APPOINTMENT_REMINDER = 'appointment_reminder';

    public const TRIGGER_PAYMENT_REMINDER = 'payment_reminder';

    public const TRIGGER_INSTALLMENT_REMINDER = 'installment_reminder';

    public const TRIGGER_FOLLOW_UP_REMINDER = 'follow_up_reminder';

    public const TRIGGER_LAB_CASE_READY = 'lab_case_ready';

    public const TRIGGER_GENERAL = 'general';

    public const AUDIENCE_PATIENT = 'patient';

    public const AUDIENCE_DOCTOR = 'doctor';

    public const AUDIENCE_CLINIC = 'clinic';

    public const AUDIENCE_INTERNAL = 'internal';

    protected $table = 'mst_wa_reminder_templates';

    protected $fillable = [
        'code',
        'name',
        'trigger_type',
        'audience_type',
        'message_body',
        'available_variables',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function triggerTypes(): array
    {
        return [
            self::TRIGGER_APPOINTMENT_REMINDER,
            self::TRIGGER_PAYMENT_REMINDER,
            self::TRIGGER_INSTALLMENT_REMINDER,
            self::TRIGGER_FOLLOW_UP_REMINDER,
            self::TRIGGER_LAB_CASE_READY,
            self::TRIGGER_GENERAL,
        ];
    }

    public static function audienceTypes(): array
    {
        return [
            self::AUDIENCE_PATIENT,
            self::AUDIENCE_DOCTOR,
            self::AUDIENCE_CLINIC,
            self::AUDIENCE_INTERNAL,
        ];
    }

    public static function triggerTypeLabels(): array
    {
        return [
            self::TRIGGER_APPOINTMENT_REMINDER => 'Reminder Janji',
            self::TRIGGER_PAYMENT_REMINDER => 'Reminder Pembayaran',
            self::TRIGGER_INSTALLMENT_REMINDER => 'Reminder Cicilan',
            self::TRIGGER_FOLLOW_UP_REMINDER => 'Reminder Kontrol',
            self::TRIGGER_LAB_CASE_READY => 'Hasil Lab Selesai',
            self::TRIGGER_GENERAL => 'Umum',
        ];
    }

    public static function audienceTypeLabels(): array
    {
        return [
            self::AUDIENCE_PATIENT => 'Pasien',
            self::AUDIENCE_DOCTOR => 'Dokter',
            self::AUDIENCE_CLINIC => 'Klinik',
            self::AUDIENCE_INTERNAL => 'Internal',
        ];
    }

    public function getTriggerTypeLabelAttribute(): string
    {
        return self::triggerTypeLabels()[$this->trigger_type] ?? $this->trigger_type;
    }

    public function getAudienceTypeLabelAttribute(): string
    {
        return self::audienceTypeLabels()[$this->audience_type] ?? $this->audience_type;
    }

    protected static function newFactory(): WaReminderTemplateFactory
    {
        return WaReminderTemplateFactory::new();
    }
}
