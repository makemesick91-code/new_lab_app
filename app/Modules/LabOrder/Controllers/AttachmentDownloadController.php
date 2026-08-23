<?php

namespace App\Modules\LabOrder\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControl;
use App\Support\Storage\ClinicalEvidenceStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — authorised download for polymorphic
 * attachments and legacy proof-of-delivery signatures.
 *
 * These were previously linked as a bare public-disk asset path, i.e. straight off
 * the publicly served disk. Now that the binaries live on the private clinical
 * disk, this is their only read path, and each one authorises against the
 * policy of the entity that actually owns it.
 *
 * The entity_type map is explicit and fails closed: an attachment whose owner
 * type is not listed here is refused rather than served under some default.
 */
class AttachmentDownloadController extends Controller
{
    use AuthorizesRequests;

    public function show(Attachment $attachment): StreamedResponse
    {
        $this->authorizeOwner($attachment);

        $path = (string) $attachment->file_path;

        abort_if($path === '', 404);

        $disk = ClinicalEvidenceStorage::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $attachment->file_name ?: basename($path), [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * Legacy proof-of-delivery signature stored as a Delivery column rather than
     * an Attachment row. Same private disk, same authorisation discipline.
     */
    public function deliverySignature(Delivery $delivery): StreamedResponse
    {
        $this->authorize('view', $delivery);

        $path = (string) $delivery->receiver_signature_path;

        abort_if($path === '', 404);

        $disk = ClinicalEvidenceStorage::disk();

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function authorizeOwner(Attachment $attachment): void
    {
        $entityId = (int) $attachment->entity_id;

        switch ($attachment->entity_type) {
            case LabOrder::ENTITY_TYPE:
                $order = LabOrder::find($entityId);
                abort_if($order === null, 404);
                $this->authorize('view', $order);

                return;

            case QualityControl::ENTITY_TYPE:
                // QualityControlPolicy::view is keyed on the owning lab order,
                // so resolve the QC's order and authorise through that policy.
                $qc = QualityControl::find($entityId);
                abort_if($qc === null, 404);
                $order = LabOrder::find($qc->lab_order_id);
                abort_if($order === null, 404);
                $this->authorize('view', [QualityControl::class, $order]);

                return;

            case Delivery::ENTITY_TYPE:
                $delivery = Delivery::find($entityId);
                abort_if($delivery === null, 404);
                $this->authorize('view', $delivery);

                return;

            default:
                // Unknown owner type: refuse rather than guess an authoriser.
                abort(404);
        }
    }
}
