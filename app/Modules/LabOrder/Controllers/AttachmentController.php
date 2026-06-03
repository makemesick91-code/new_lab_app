<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Requests\UploadAttachmentRequest;
use App\Modules\LabOrder\Services\AttachmentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class AttachmentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AttachmentService $attachmentService,
    ) {}

    public function upload(UploadAttachmentRequest $request, LabOrder $labOrder): RedirectResponse
    {
        $this->authorize('uploadAttachment', $labOrder);

        $this->attachmentService->upload(
            $labOrder,
            $request->file('file'),
            $request->validated()['category'],
        );

        return redirect()
            ->route('lab-orders.show', $labOrder)
            ->with('status', 'Attachment uploaded successfully.');
    }

    public function destroy(LabOrder $labOrder, Attachment $attachment): RedirectResponse
    {
        $this->authorize('deleteAttachment', $labOrder);

        abort_unless(
            $attachment->entity_type === LabOrder::ENTITY_TYPE && (int) $attachment->entity_id === $labOrder->id,
            404,
        );

        $this->attachmentService->delete($attachment);

        return redirect()
            ->route('lab-orders.show', $labOrder)
            ->with('status', 'Attachment deleted.');
    }
}
