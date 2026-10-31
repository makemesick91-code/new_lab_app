<?php

declare(strict_types=1);

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — who may record that a
| refund was actually paid out. SOURCE-CONTROLLED on purpose: no env() here, so no
| host can widen it; changing it is a reviewed code change and a deploy.
|
| pilot.branch_codes — the branches where settlement may run at all (RF-3 order:
|                      the refund request pilot is SPN4). Fail-closed: an empty or
|                      malformed list admits no branch; MAIN is never admitted.
| pilot.settlers     — the explicit settler designations: a list of
|                      ['user_id' => int, 'branch_codes' => list<string>].
|                      SHIPS EMPTY: the Owner approved RF-10 (settler ≠ approver)
|                      but has NOT appointed the role or the people who disburse
|                      refunds, so nobody can settle. A malformed entry, a duplicate
|                      user or an unknown code voids the WHOLE list (deny-all).
|                      A designation only NARROWS: the account must also be usable,
|                      hold `settle_rme_refund`, and never be the request's approver.
|
| evidence           — limits for the disbursement proof (bank transfer receipt or
|                      signed cash handover). Stored on the private disk
|                      `rme_refund_settlement_evidence`, never at a public URL.
|
| The capability itself is the feature flag `rme.billing_refund_settlement`
| (default OFF), which also requires `rme.billing_refund_request`.
*/
return [
    'pilot' => [
        'branch_codes' => ['SPN4'],
        'settlers' => [],
    ],

    'evidence' => [
        'max_kilobytes' => 5120,
    ],
];
