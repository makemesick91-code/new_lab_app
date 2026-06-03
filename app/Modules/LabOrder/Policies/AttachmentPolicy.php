<?php

namespace App\Modules\LabOrder\Policies;

use App\Models\User;
use App\Modules\LabOrder\Models\Attachment;

/**
 * Authorization for attachment records. Order-scoped upload/delete checks are
 * enforced by LabOrderPolicy::uploadAttachment / deleteAttachment using the
 * parent order; this policy guards direct attachment access.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->canAny(['manage_lab_orders', 'view_lab_orders']);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $user->canAny(['manage_lab_orders', 'update_lab_orders']);
    }
}
