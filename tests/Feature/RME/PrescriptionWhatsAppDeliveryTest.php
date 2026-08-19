<?php

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — FIX-02.
 *
 * Prescriptions are handed to patients server-to-server through the official
 * WhatsApp Business Platform (Meta Cloud API). There is no wa.me link, no
 * WhatsApp Web and no browser redirect.
 *
 * Every test here is hermetic: no real network call is ever made.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;
use App\Modules\Prescription\Gateways\DisabledWhatsAppGateway;
use App\Modules\Prescription\Gateways\WhatsAppGatewayInterface;
use App\Modules\Prescription\Models\PrescriptionWhatsAppDelivery;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\Prescription\Services\PrescriptionWhatsAppDeliveryService;
use App\Modules\Prescription\Services\WhatsAppRecipientResolver;
use Database\Seeders\BranchSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create([
        'code' => 'WAB1', 'name' => 'Cabang WA', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);

    $this->patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Pasien WA',
        'whatsapp_number' => '081234567890',
    ]);

    $this->visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $this->prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $this->visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);

    $this->sender = userWith(['view_clinic_visits', 'manage_clinic_visits', 'send_prescription_whatsapp']);
});

function fcoUseCloudApi(): void
{
    config()->set('whatsapp.enabled', true);
    config()->set('whatsapp.driver', 'cloud_api');
    config()->set('whatsapp.cloud_api.phone_number_id', '1234567890');
    config()->set('whatsapp.cloud_api.access_token', 'SECRET-TOKEN-VALUE');
    config()->set('whatsapp.prescription_template.name', 'resep_siap');
    config()->set('whatsapp.prescription_template.language', 'id');
}

/* -------------------------------------------------------- disabled by default */

it('binds the disabled gateway and opens no socket when the integration is off', function () {
    expect(app(WhatsAppGatewayInterface::class))->toBeInstanceOf(DisabledWhatsAppGateway::class);

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    Http::assertNothingSent();
    expect(PrescriptionWhatsAppDelivery::query()->count())->toBe(0);
});

it('tells the operator plainly that WhatsApp is not configured yet', function () {
    $this->actingAs($this->sender)
        ->post(route('rme.prescriptions.whatsapp.send', $this->prescription), ['confirm' => 1])
        ->assertRedirect()
        ->assertSessionHas('error');

    Http::assertNothingSent();
});

/* -------------------------------------------------------- recipient normalisation */

it('normalises Indonesian numbers to E.164 digits and rejects unusable ones', function () {
    $resolver = app(WhatsAppRecipientResolver::class);

    expect($resolver->normalise('081234567890'))->toBe('6281234567890')
        ->and($resolver->normalise('+62 812-3456-7890'))->toBe('6281234567890')
        ->and($resolver->normalise('6281234567890'))->toBe('6281234567890')
        ->and($resolver->normalise('81234567890'))->toBe('6281234567890')
        ->and($resolver->normalise(''))->toBeNull()
        ->and($resolver->normalise('abc'))->toBeNull()
        ->and($resolver->normalise('123'))->toBeNull();
});

it('refuses to send when the patient has no usable number', function () {
    fcoUseCloudApi();
    $this->patient->update(['whatsapp_number' => null, 'phone' => null]);

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription->refresh(), $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    Http::assertNothingSent();
});

/* -------------------------------------------------------- the real Cloud API contract */

it('posts an approved template to the official Cloud API endpoint', function () {
    fcoUseCloudApi();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC123']]], 200),
    ]);

    $delivery = app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://graph.facebook.com/v23.0/1234567890/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer SECRET-TOKEN-VALUE')
            && $body['messaging_product'] === 'whatsapp'
            && $body['to'] === '6281234567890'
            // A proactive hand-off is only deliverable as a template.
            && $body['type'] === 'template'
            && $body['template']['name'] === 'resep_siap'
            && $body['template']['language']['code'] === 'id';
    });

    expect($delivery->status)->toBe(PrescriptionWhatsAppDelivery::STATUS_SENT)
        ->and($delivery->provider_message_id)->toBe('wamid.ABC123')
        ->and($delivery->recipient_msisdn)->toBe('6281234567890')
        ->and($delivery->sent_by)->toBe($this->sender->id);
});

it('never sends clinical detail or an identity number in the message', function () {
    fcoUseCloudApi();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

    $this->patient->update(['ktp_number' => '7371010101010001']);
    app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription->refresh(), $this->sender);

    Http::assertSent(function ($request) {
        $payload = json_encode($request->data());

        return ! str_contains($payload, '7371010101010001')
            && ! str_contains((string) $payload, 'ktp');
    });
});

it('fails closed on a provider rejection and leaves the prescription untouched', function () {
    fcoUseCloudApi();
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['code' => 131026, 'message' => 'Message undeliverable'],
        ], 400),
    ]);

    $before = $this->prescription->only(['status', 'prescription_canvas_path', 'printed_at']);

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    expect($this->prescription->refresh()->only(['status', 'prescription_canvas_path', 'printed_at']))->toBe($before);

    $delivery = PrescriptionWhatsAppDelivery::query()->firstOrFail();
    expect($delivery->status)->toBe(PrescriptionWhatsAppDelivery::STATUS_FAILED)
        ->and($delivery->provider_error_code)->toBe('131026')
        ->and($delivery->provider_message_id)->toBeNull();
});

it('treats a provider 5xx and a timeout as retryable failures', function () {
    fcoUseCloudApi();
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 500]], 500)]);

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    expect(PrescriptionWhatsAppDelivery::query()->firstOrFail()->status)
        ->toBe(PrescriptionWhatsAppDelivery::STATUS_FAILED);

    PrescriptionWhatsAppDelivery::query()->delete();

    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription->refresh(), $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);
});

it('never lets a misconfigured endpoint reach a host outside the allowlist', function () {
    fcoUseCloudApi();
    config()->set('whatsapp.cloud_api.base_url', 'https://evil.example.com');

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    Http::assertNothingSent();
});

it('refuses a plain-HTTP endpoint', function () {
    fcoUseCloudApi();
    config()->set('whatsapp.cloud_api.base_url', 'http://graph.facebook.com');

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    Http::assertNothingSent();
});

/* -------------------------------------------------------- duplicate protection */

it('refuses a second send unless the operator explicitly asks to resend', function () {
    fcoUseCloudApi();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);

    $service = app(PrescriptionWhatsAppDeliveryService::class);
    $service->send($this->prescription, $this->sender);

    expect(fn () => $service->send($this->prescription->refresh(), $this->sender))
        ->toThrow(WhatsAppDeliveryException::class);

    expect(PrescriptionWhatsAppDelivery::query()->where('status', 'sent')->count())->toBe(1);

    // An explicit resend is allowed and recorded separately.
    $service->send($this->prescription->refresh(), $this->sender, allowResend: true);

    expect(PrescriptionWhatsAppDelivery::query()->where('status', 'sent')->count())->toBe(2);
});

/* -------------------------------------------------------- authorization + isolation */

it('denies sending to a role without the prescription WhatsApp permission', function () {
    fcoUseCloudApi();
    $viewer = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs($viewer)
        ->post(route('rme.prescriptions.whatsapp.send', $this->prescription), ['confirm' => 1])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('requires an explicit confirmation', function () {
    fcoUseCloudApi();

    $this->actingAs($this->sender)
        ->post(route('rme.prescriptions.whatsapp.send', $this->prescription), [])
        ->assertSessionHasErrors('confirm');

    Http::assertNothingSent();
});

it('refuses to send a prescription belonging to another working branch', function () {
    fcoUseCloudApi();

    $other = Branch::factory()->create(['code' => 'WAB2', 'name' => 'Cabang WA 2', 'is_active' => true, 'is_rme_enabled' => true]);
    $kasir = userInRole('Kasir');
    $kasir->givePermissionTo('send_prescription_whatsapp');
    rmeMakeKasirActive($kasir, $other);

    expect(fn () => app(PrescriptionWhatsAppDeliveryService::class)->send($this->prescription, $kasir))
        ->toThrow(WhatsAppDeliveryException::class);

    Http::assertNothingSent();
});

/* -------------------------------------------------------- no deep links anywhere */

it('offers a server-side send, never a wa.me deep link, on the prescription page', function () {
    fcoUseCloudApi();

    $html = $this->actingAs($this->sender)
        ->get(route('rme.visits.prescription.show', $this->visit))
        ->assertOk()
        ->getContent();

    expect($html)->toContain(route('rme.prescriptions.whatsapp.send', $this->prescription))
        ->and($html)->not->toContain('wa.me')
        ->and($html)->not->toContain('api.whatsapp.com')
        ->and($html)->not->toContain('web.whatsapp.com')
        ->and($html)->not->toContain('SECRET-TOKEN-VALUE');
});
