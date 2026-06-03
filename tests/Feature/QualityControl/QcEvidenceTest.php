<?php

use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('public');
});

it('uploads QC evidence and links it to the order', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['upload_qc_evidence']))
        ->post(route('quality-control.evidence.store', $order), [
            'category' => 'QC_PHOTO',
            'file' => UploadedFile::fake()->create('evidence.pdf', 200, 'application/pdf'),
        ])
        ->assertRedirect(route('quality-control.show', $order));

    $attachment = Attachment::first();
    expect($attachment)->not->toBeNull();
    expect($attachment->entity_type)->toBe('trx_lab_orders');
    expect($attachment->entity_id)->toBe($order->id);
    expect($attachment->category)->toBe('QC_PHOTO');
    Storage::disk('public')->assertExists($attachment->file_path);
});

it('creates an audit log on QC evidence upload', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['upload_qc_evidence']))
        ->post(route('quality-control.evidence.store', $order), [
            'category' => 'QC_EVIDENCE',
            'file' => UploadedFile::fake()->create('e.png', 100, 'image/png'),
        ]);

    expect(AuditLog::where('entity_id', $order->id)->where('action', 'UPLOAD_QC_EVIDENCE')->exists())->toBeTrue();
});

it('rejects an unsupported evidence file type', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['upload_qc_evidence']))
        ->post(route('quality-control.evidence.store', $order), [
            'category' => 'QC_EVIDENCE',
            'file' => UploadedFile::fake()->create('malware.exe', 100),
        ])
        ->assertSessionHasErrors('file');

    expect(Attachment::count())->toBe(0);
});

it('requires a category for QC evidence', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['upload_qc_evidence']))
        ->post(route('quality-control.evidence.store', $order), [
            'file' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('category');
});

it('rejects an invalid evidence category', function () {
    $order = qcPendingOrder();

    $this->actingAs(superAdmin())
        ->post(route('quality-control.evidence.store', $order), [
            'category' => 'NOT_A_CATEGORY',
            'file' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('category');
});

it('denies QC evidence upload without permission', function () {
    $order = qcPendingOrder();

    $this->actingAs(userWith(['view_quality_control']))
        ->post(route('quality-control.evidence.store', $order), [
            'category' => 'QC_PHOTO',
            'file' => UploadedFile::fake()->create('e.pdf', 100, 'application/pdf'),
        ])
        ->assertForbidden();
});
