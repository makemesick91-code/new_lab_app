<?php

/*
|--------------------------------------------------------------------------
| RME invoice correction — pilot branch admission
|--------------------------------------------------------------------------
|
| ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195).
|
| The feature flag `rme.invoice_correction` is a GLOBAL switch: on its own it
| would expose correction to every RME branch at once. The owner approved a
| controlled pilot on 2026-10-21 for ONE branch only — Cabang Sunu (SPN4).
|
| This list is the server-side allowlist of branches where a correction may be
| SUBMITTED, APPROVED (which applies it — D1a) or APPLIED. It is deliberately
| source-controlled and does NOT read the environment, exactly like the strict
| tariff list of rule 194: widening a financial capability to another branch is
| an owner decision that must arrive as a reviewed commit, never as a host edit
| nobody can see. The flag stays the environment kill switch (ON/OFF); this list
| decides WHERE an ON flag reaches.
|
| Rules for this file:
|   - FAIL CLOSED. An empty list admits no branch, even with the flag ON.
|   - Codes are canonical branch codes (rule 92/136). A deprecated alias
|     (SUN4, TKM1) is canonicalized before comparison; exact-token matching
|     only — never a prefix.
|   - MAIN is never admitted (it is not an RME branch), whatever is listed.
|   - A listed code whose branch is inactive or not RME-enabled admits nothing.
|   - Rejecting or cancelling an EXISTING correction is NOT gated by this list:
|     those transitions only ever de-escalate, and they are how an active
|     correction outside the pilot is resolved during a rollback (rule 195 §14).
|   - Never add a branch the owner has not approved.
|
*/

return [

    'pilot' => [
        'approval' => [
            'approved_by' => 'Owner',
            'approved_on' => '2026-10-21',
            'source' => 'Owner answers recorded in ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2',
        ],

        'branch_codes' => ['SPN4'],
    ],

];
