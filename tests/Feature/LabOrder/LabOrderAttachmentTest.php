<?php

use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('public');
    Storage::fake('clinical_evidence');
});

it('uploads an attachment and stores metadata only', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'PRESCRIPTION',
            'file' => UploadedFile::fake()->create('rx.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect(route('lab-orders.show', $order));

    $attachment = Attachment::first();
    expect($attachment)->not->toBeNull();
    expect($attachment->entity_type)->toBe('trx_lab_orders');
    expect($attachment->entity_id)->toBe($order->id);
    expect($attachment->file_path)->not->toBeEmpty();
    Storage::disk('clinical_evidence')->assertExists($attachment->file_path);
});

it('creates an audit log on attachment upload', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'CASE_PHOTO',
            'file' => UploadedFile::fake()->create('photo.png', 200, 'image/png'),
        ]);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'UPLOAD_ATTACHMENT')->exists())->toBeTrue();
});

it('rejects an unsupported file type', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'OTHER_DOCUMENT',
            'file' => UploadedFile::fake()->create('malware.exe', 100),
        ])
        ->assertSessionHasErrors('file');

    expect(Attachment::count())->toBe(0);
});

it('rejects a file larger than 10 MB', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'OTHER_DOCUMENT',
            'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
        ])
        ->assertSessionHasErrors('file');
});

it('requires a category', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'file' => UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('category');
});

it('soft deletes an attachment and audits it', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'PRESCRIPTION',
            'file' => UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf'),
        ]);

    $attachment = Attachment::first();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->delete(route('lab-orders.attachments.destroy', [$order, $attachment]))
        ->assertRedirect(route('lab-orders.show', $order));

    expect(Attachment::find($attachment->id))->toBeNull();
    expect(Attachment::withTrashed()->find($attachment->id))->not->toBeNull();
    expect(AuditLog::where('entity_id', $order->id)->where('action', 'DELETE_ATTACHMENT')->exists())->toBeTrue();
});

it('denies attachment upload without permission', function () {
    $order = LabOrder::factory()->create();

    $this->actingAs(userWith(['view_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'PRESCRIPTION',
            'file' => UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});

it('forbids uploading to a cancelled order', function () {
    $order = LabOrder::factory()->cancelled()->create();

    $this->actingAs(userWith(['manage_lab_orders']))
        ->post(route('lab-orders.attachments.upload', $order), [
            'category' => 'PRESCRIPTION',
            'file' => UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});

it('redirects guests from attachment upload to login', function () {
    $order = LabOrder::factory()->create();

    $this->post(route('lab-orders.attachments.upload', $order), [])
        ->assertRedirect(route('login'));
});
