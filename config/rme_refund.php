<?php

declare(strict_types=1);

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (Sprint 4/7, rule 215) — refund
| request pilot. SOURCE-CONTROLLED on purpose: no env() here, so no host can widen
| the pilot; changing it is a reviewed code change.
|
| pilot.branch_codes  — owner decision RF-3: SPN4 only. Fail-closed: an empty or
|                       malformed list admits no branch; MAIN is never admitted.
| pilot.identities    — owner decision RF-1a: EMPTY, so nobody is admitted. The
|                       Sprint 2 maker/checker pair is NOT inherited. Filled only
|                       at activation with accounts whose human separation was
|                       verified; a maker id that is also a reviewer id voids the
|                       whole list. It only NARROWS the role + branch authority of
|                       RF-1; it never grants it.
|
| The capability itself is the feature flag `rme.billing_refund_request`
| (default OFF). Nothing here moves money: settlement is Sprint 5.
*/
return [
    'pilot' => [
        'branch_codes' => ['SPN4'],
        'identities' => [
            'maker_user_ids' => [],
            'reviewer_user_ids' => [],
        ],
    ],
];
