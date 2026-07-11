<?php

use App\Modules\LabOrder\Models\LabWorkflowEvidence;

/*
|--------------------------------------------------------------------------
| FIX-LAB-TECHNICIAN-ROLE-ASSIGNMENT-UPLOAD-COMPRESSION — canonical upload
| compression contract for EVERY Lab Workflow V2 evidence file.
|--------------------------------------------------------------------------
|
| All Lab Workflow uploads flow through LabWorkflowEvidenceService, which
| delegates to LabEvidenceImageOptimizer with the profile mapped here.
| Photos are re-encoded as JPEG (compatibility-safe); signatures stay PNG
| (transparency + sharpness). Compression NEVER trades away readability:
| quality never drops below the per-profile minimum, iterations are bounded,
| and an image that still cannot fit the hard cap is REJECTED (fail closed)
| instead of being stored blurred or corrupted.
|
| PDF is intentionally NOT an accepted Lab Workflow evidence type — the
| evidence pipeline is image-only by design (see ALLOWED mimes in the
| service). No PDF optimizer exists on the VPS, and faking compression is
| forbidden; if PDFs are ever needed they require their own governed sprint.
*/

return [

    // Hard input cap BEFORE compression (bytes). FormRequests also cap at
    // 10240 KB — this is the server-side service boundary.
    'max_input_bytes' => 10 * 1024 * 1024,

    // Signature canvas payloads are far smaller by nature.
    'max_signature_input_bytes' => 512 * 1024,

    // Decompression-bomb guards: checked from header bytes BEFORE full decode.
    'max_pixels' => 50_000_000,   // ~50 MP
    'max_dimension' => 12000,     // px, either edge

    // Adaptive-compression profiles. quality_step lowers quality gradually;
    // once min_quality is reached the long edge shrinks by dimension_step
    // until hard_max_bytes fits or max_iterations runs out (then: reject).
    'profiles' => [
        // SPK / text documents — small text must stay readable on zoom.
        'document' => [
            'max_long_edge' => 2200,
            'quality' => 82,
            'min_quality' => 68,
            'quality_step' => 5,
            'dimension_step' => 0.85,
            'min_long_edge' => 1200,
            'target_bytes' => 700 * 1024,
            'hard_max_bytes' => 1024 * 1024,
            'max_iterations' => 6,
        ],
        // Model / process photos — clinical/technical detail must survive.
        'photo' => [
            'max_long_edge' => 1800,
            'quality' => 78,
            'min_quality' => 62,
            'quality_step' => 5,
            'dimension_step' => 0.85,
            'min_long_edge' => 1000,
            'target_bytes' => 600 * 1024,
            'hard_max_bytes' => 1024 * 1024,
            'max_iterations' => 6,
        ],
        // Delivery / location proof photos.
        'delivery' => [
            'max_long_edge' => 1600,
            'quality' => 75,
            'min_quality' => 62,
            'quality_step' => 5,
            'dimension_step' => 0.85,
            'min_long_edge' => 900,
            'target_bytes' => 500 * 1024,
            'hard_max_bytes' => 1024 * 1024,
            'max_iterations' => 6,
        ],
        // Signature canvases — PNG kept (transparency + sharp strokes).
        'signature' => [
            'max_width' => 1200,
            'max_height' => 600,
            'target_bytes' => 200 * 1024,
            'hard_max_bytes' => 300 * 1024,
            'max_iterations' => 3,
        ],
    ],

    // Evidence type → profile. Every LabWorkflowEvidence::TYPES entry MUST be
    // mapped here (asserted by tests) so no upload can bypass compression.
    'type_profiles' => [
        LabWorkflowEvidence::TYPE_SPK_PHOTO => 'document',
        LabWorkflowEvidence::TYPE_MODEL_PHOTO_BRANCH => 'photo',
        LabWorkflowEvidence::TYPE_PICKUP_PHOTO => 'photo',
        LabWorkflowEvidence::TYPE_PRE_DELIVERY_HANDOVER_PHOTO => 'photo',
        LabWorkflowEvidence::TYPE_DELIVERY_LOCATION_PHOTO => 'delivery',
        LabWorkflowEvidence::TYPE_COURIER_SIGNATURE => 'signature',
        LabWorkflowEvidence::TYPE_RECIPIENT_SIGNATURE => 'signature',
    ],
];
