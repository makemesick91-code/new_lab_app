<?php

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\WaReminderTemplate\Models\WaReminderTemplate;
use Database\Seeders\WaReminderTemplateSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedAccessControl();

    $this->manager = userWith(['manage_clinic_master_data']);
    $this->viewer = userWith(['view_clinic_master_data']);
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('denies users without any clinic master data permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertForbidden();
});

it('allows a viewer with view_clinic_master_data to access the index', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertViewIs('settings.wa-reminder-templates.index');
});

it('lists templates visible to a viewer', function () {
    WaReminderTemplate::factory()->create([
        'code' => 'APPT-TEST',
        'name' => 'Template Janji Test',
        'trigger_type' => WaReminderTemplate::TRIGGER_APPOINTMENT_REMINDER,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Halo pasien, ini pengingat.',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertSee('APPT-TEST')
        ->assertSee('Template Janji Test');
});

it('denies a viewer from opening the create page', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.create'))
        ->assertForbidden();
});

it('denies a viewer from storing a template', function () {
    $this->actingAs($this->viewer)
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'NEW',
            'name' => 'Baru',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan baru.',
        ])
        ->assertForbidden();
});

it('denies a viewer from updating a template', function () {
    $template = WaReminderTemplate::factory()->create();

    $this->actingAs($this->viewer)
        ->put(route('settings.wa-reminder-templates.update', $template), [
            'code' => $template->code,
            'name' => 'Diubah Viewer',
            'trigger_type' => $template->trigger_type,
            'audience_type' => $template->audience_type,
            'message_body' => 'Pesan diubah.',
        ])
        ->assertForbidden();
});

it('denies a viewer from deleting a template', function () {
    $template = WaReminderTemplate::factory()->create();

    $this->actingAs($this->viewer)
        ->delete(route('settings.wa-reminder-templates.destroy', $template))
        ->assertForbidden();
});

it('lets a manager open the create page', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.wa-reminder-templates.create'))
        ->assertOk()
        ->assertViewIs('settings.wa-reminder-templates.create');
});

// ---------------------------------------------------------------------------
// CRUD
// ---------------------------------------------------------------------------

it('stores a valid template', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'PAY-REM',
            'name' => 'Reminder Pembayaran Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_PAYMENT_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Halo pasien, silakan bayar.',
            'sort_order' => 10,
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.wa-reminder-templates.index'));

    $this->assertDatabaseHas('mst_wa_reminder_templates', [
        'code' => 'PAY-REM',
        'name' => 'Reminder Pembayaran Test',
        'trigger_type' => WaReminderTemplate::TRIGGER_PAYMENT_REMINDER,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'sort_order' => 10,
        'is_active' => true,
    ]);
});

it('normalizes the code to uppercase on store', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'gen_reminder',
            'name' => 'Umum',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_INTERNAL,
            'message_body' => 'Pesan umum.',
        ])
        ->assertRedirect(route('settings.wa-reminder-templates.index'));

    $this->assertDatabaseHas('mst_wa_reminder_templates', ['code' => 'GEN_REMINDER']);
    $this->assertDatabaseMissing('mst_wa_reminder_templates', ['code' => 'gen_reminder']);
});

it('updates a template', function () {
    $template = WaReminderTemplate::factory()->create([
        'code' => 'ORIG-TPL',
        'name' => 'Original',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan asli.',
        'sort_order' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->put(route('settings.wa-reminder-templates.update', $template), [
            'code' => 'ORIG-TPL',
            'name' => 'Diperbarui',
            'trigger_type' => WaReminderTemplate::TRIGGER_FOLLOW_UP_REMINDER,
            'audience_type' => WaReminderTemplate::AUDIENCE_CLINIC,
            'message_body' => 'Pesan diperbarui.',
            'sort_order' => 20,
            'is_active' => '0',
        ])
        ->assertRedirect(route('settings.wa-reminder-templates.index'));

    $template->refresh();
    expect($template->name)->toBe('Diperbarui')
        ->and($template->trigger_type)->toBe(WaReminderTemplate::TRIGGER_FOLLOW_UP_REMINDER)
        ->and($template->audience_type)->toBe(WaReminderTemplate::AUDIENCE_CLINIC)
        ->and($template->sort_order)->toBe(20)
        ->and($template->is_active)->toBeFalse();
});

it('soft deletes a template', function () {
    $template = WaReminderTemplate::factory()->create();

    $this->actingAs($this->manager)
        ->delete(route('settings.wa-reminder-templates.destroy', $template))
        ->assertRedirect(route('settings.wa-reminder-templates.index'));

    expect(WaReminderTemplate::find($template->id))->toBeNull();
    expect(WaReminderTemplate::withTrashed()->find($template->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('requires code', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => '',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('code');
});

it('requires name', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => '',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('name');
});

it('requires trigger_type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => '',
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('trigger_type');
});

it('requires audience_type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => '',
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('audience_type');
});

it('requires message_body', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => '',
        ])
        ->assertSessionHasErrors('message_body');
});

it('rejects an invalid trigger_type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => 'invalid_trigger',
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('trigger_type');
});

it('rejects an invalid audience_type', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => 'invalid_audience',
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('audience_type');
});

it('rejects a duplicate code', function () {
    WaReminderTemplate::factory()->create(['code' => 'DUPL-TPL']);

    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'DUPL-TPL',
            'name' => 'Duplikat',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
        ])
        ->assertSessionHasErrors('code');
});

it('rejects a negative sort_order', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
            'sort_order' => -1,
        ])
        ->assertSessionHasErrors('sort_order');
});

it('rejects a non-integer sort_order', function () {
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
            'sort_order' => 'abc',
        ])
        ->assertSessionHasErrors('sort_order');
});

it('rejects available_variables items that exceed 100 characters', function () {
    // available_variables_raw is the textarea field; prepareForValidation converts lines to an array.
    // Validation rule: available_variables.* max:100
    $this->actingAs($this->manager)
        ->from(route('settings.wa-reminder-templates.create'))
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'TEST',
            'name' => 'Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
            'message_body' => 'Pesan.',
            'available_variables_raw' => str_repeat('x', 101),
        ])
        ->assertSessionHasErrors('available_variables.0');
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('searches templates by code, name, or message_body', function () {
    WaReminderTemplate::factory()->create([
        'code' => 'APPT-SRC',
        'name' => 'Pengingat Janji',
        'trigger_type' => WaReminderTemplate::TRIGGER_APPOINTMENT_REMINDER,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan janji temu.',
    ]);
    WaReminderTemplate::factory()->create([
        'code' => 'PAY-SRC',
        'name' => 'Pengingat Bayar',
        'trigger_type' => WaReminderTemplate::TRIGGER_PAYMENT_REMINDER,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan tagihan.',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['search' => 'APPT-SRC']))
        ->assertOk()
        ->assertSee('APPT-SRC')
        ->assertDontSee('PAY-SRC');

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['search' => 'tagihan']))
        ->assertOk()
        ->assertSee('Pengingat Bayar')
        ->assertDontSee('Pengingat Janji');
});

it('filters templates by trigger_type', function () {
    WaReminderTemplate::factory()->create([
        'code' => 'APPT-FLT',
        'name' => 'Janji Filter',
        'trigger_type' => WaReminderTemplate::TRIGGER_APPOINTMENT_REMINDER,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan janji.',
    ]);
    WaReminderTemplate::factory()->create([
        'code' => 'GEN-FLT',
        'name' => 'Umum Filter',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan umum.',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['trigger_type' => WaReminderTemplate::TRIGGER_APPOINTMENT_REMINDER]))
        ->assertOk()
        ->assertSee('Janji Filter')
        ->assertDontSee('Umum Filter');
});

it('filters templates by audience_type', function () {
    WaReminderTemplate::factory()->create([
        'code' => 'PAT-AUD',
        'name' => 'Template Pasien',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan pasien.',
    ]);
    WaReminderTemplate::factory()->create([
        'code' => 'CLI-AUD',
        'name' => 'Template Klinik',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_CLINIC,
        'message_body' => 'Pesan klinik.',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['audience_type' => WaReminderTemplate::AUDIENCE_PATIENT]))
        ->assertOk()
        ->assertSee('Template Pasien')
        ->assertDontSee('Template Klinik');
});

it('filters templates by active status', function () {
    WaReminderTemplate::factory()->create([
        'code' => 'ACTIVE-T',
        'name' => 'Template Aktif',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan aktif.',
        'is_active' => true,
    ]);
    WaReminderTemplate::factory()->inactive()->create([
        'code' => 'INACT-T',
        'name' => 'Template Nonaktif',
        'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
        'audience_type' => WaReminderTemplate::AUDIENCE_PATIENT,
        'message_body' => 'Pesan nonaktif.',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['is_active' => '1']))
        ->assertOk()
        ->assertSee('Template Aktif')
        ->assertDontSee('Template Nonaktif');

    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index', ['is_active' => '0']))
        ->assertOk()
        ->assertSee('Template Nonaktif')
        ->assertDontSee('Template Aktif');
});

// ---------------------------------------------------------------------------
// Seeder
// ---------------------------------------------------------------------------

it('seeds the 5 default WA reminder templates', function () {
    test()->seed(WaReminderTemplateSeeder::class);

    expect(WaReminderTemplate::count())->toBe(5);

    $expected = [
        ['code' => 'APPOINTMENT_REMINDER',  'trigger_type' => 'appointment_reminder',  'sort_order' => 10],
        ['code' => 'PAYMENT_REMINDER',      'trigger_type' => 'payment_reminder',       'sort_order' => 20],
        ['code' => 'INSTALLMENT_REMINDER',  'trigger_type' => 'installment_reminder',   'sort_order' => 30],
        ['code' => 'FOLLOW_UP_REMINDER',    'trigger_type' => 'follow_up_reminder',     'sort_order' => 40],
        ['code' => 'LAB_CASE_READY',        'trigger_type' => 'lab_case_ready',         'sort_order' => 50],
    ];

    foreach ($expected as $row) {
        $this->assertDatabaseHas('mst_wa_reminder_templates', $row + ['is_active' => true]);
    }
});

it('has an idempotent WA reminder template seeder', function () {
    test()->seed(WaReminderTemplateSeeder::class);
    test()->seed(WaReminderTemplateSeeder::class);

    expect(WaReminderTemplate::count())->toBe(5);
});

// ---------------------------------------------------------------------------
// Safety — no workflow side effects
// ---------------------------------------------------------------------------

it('does not create Invoice, Payment, LabOrder, or RME records when a template is stored', function () {
    $this->actingAs($this->manager)
        ->post(route('settings.wa-reminder-templates.store'), [
            'code' => 'SAFETYTPL',
            'name' => 'Safety Test',
            'trigger_type' => WaReminderTemplate::TRIGGER_GENERAL,
            'audience_type' => WaReminderTemplate::AUDIENCE_INTERNAL,
            'message_body' => 'Pesan safety test.',
            'is_active' => '1',
        ])
        ->assertRedirect(route('settings.wa-reminder-templates.index'));

    expect(Invoice::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(LabOrder::count())->toBe(0);

    expect(Schema::hasTable('trx_medical_records'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// UI
// ---------------------------------------------------------------------------

it('index shows warning that WhatsApp is not sent automatically', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertSee('belum mengirim WhatsApp otomatis');
});

it('index shows variable examples', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertSee('patient_name');
});

it('create form shows variable examples', function () {
    $this->actingAs($this->manager)
        ->get(route('settings.wa-reminder-templates.create'))
        ->assertOk()
        ->assertSee('patient_name');
});

it('sidebar shows Template Reminder WA for a permitted user', function () {
    $this->actingAs($this->viewer)
        ->get(route('settings.wa-reminder-templates.index'))
        ->assertOk()
        ->assertSee('Template Reminder WA');
});

it('sidebar hides Template Reminder WA for an unauthorized user', function () {
    $this->actingAs(userWith([]))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Template Reminder WA');
});

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

it('has all required named routes for wa-reminder-templates', function () {
    expect(route('settings.wa-reminder-templates.index'))->toBeString();
    expect(route('settings.wa-reminder-templates.create'))->toBeString();
    expect(route('settings.wa-reminder-templates.store'))->toBeString();

    $template = WaReminderTemplate::factory()->create();
    expect(route('settings.wa-reminder-templates.edit', $template))->toBeString();
    expect(route('settings.wa-reminder-templates.update', $template))->toBeString();
    expect(route('settings.wa-reminder-templates.destroy', $template))->toBeString();
});
