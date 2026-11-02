<?php

/*
|--------------------------------------------------------------------------
| RME doctor performed treatments — pilot branch admission
|--------------------------------------------------------------------------
|
| ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15; owner DPT-Q8).
|
| The feature flag `rme.doctor_performed_treatments` is a GLOBAL switch: ON by
| itself it would expose the doctor's "Tindakan Dilakukan" record to every RME
| branch at once. The Owner approved a pilot for ONE branch and designated it on
| 2026-11-02: Cabang Sunu (SPN4).
|
| This list is the server-side allowlist of branches where the record may be
| authored, shown, projected to the cashier and passed to the rule 218
| cross-check. It is deliberately source-controlled and does NOT read the
| environment: widening a clinical capability to another branch is an Owner
| decision that must arrive as a reviewed commit, never as a host edit nobody can
| see. The flag stays the environment kill switch (ON/OFF); this list decides
| WHERE an ON flag reaches.
|
| Rules for this file (RmePerformedTreatmentPilotScope):
|   - FAIL CLOSED. Empty, malformed (not a list of strings), an unknown code,
|     MAIN listed, or more than ONE branch: the configuration is invalid and
|     admits NO branch, even with the flag ON. The readiness probe
|     (`rme:performed-treatment-readiness`) reports which.
|   - Codes are canonical branch codes (rule 92/136). A deprecated alias (SUN4)
|     is canonicalized before comparison; the live branch must carry the
|     canonical code exactly — never a prefix.
|   - A listed code whose branch is inactive or not RME-enabled admits nothing.
|   - This list is this capability's OWN. The invoice-correction (D1c) and
|     refund (RF-3) pilots also name SPN4; neither authorizes anything here.
|   - Never add a branch the Owner has not approved.
|
*/

return [

    'pilot' => [
        'approval' => [
            'decision' => 'DPT-Q8',
            'approved_by' => 'Owner',
            'approved_on' => '2026-11-02',
            'source' => 'Owner designation recorded in RATIFICATION-RME-DOCTOR-PERFORMED-TREATMENTS-OWNER-DECISIONS-1 (in-session answers, 2026-11-02 WITA)',
        ],

        'branch_codes' => ['SPN4'],
    ],

];
