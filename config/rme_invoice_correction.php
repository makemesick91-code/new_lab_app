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

        /*
        | HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 — owner
        | decision D1e (2026-10-21, rule 197). The branch list above decides WHERE
        | the pilot reaches; this list decides WHO may act in it. Only the named
        | ACCOUNTS — never a role — may move money through a correction:
        |
        |   maker_user_ids   — SUBMIT a correction (production: user 29, Admin Sunu)
        |   checker_user_ids — APPROVE (which applies it, D1a), REJECT, and the
        |                      recovery APPLY of a pre-D1a APPROVED row
        |                      (production: user 1, IT Support)
        |
        | Every other account is refused — other Front Office accounts, other Super
        | Admins, every role — whatever its permissions say. Super Admin passes
        | every policy through Gate::before, so the control is the SERVICE, which
        | asserts this list under the invoice row lock.
        |
        | Rules for this list (RmeInvoiceCorrectionPilotIdentity):
        |   - SOURCE-CONTROLLED, no environment read — the cohort is an owner
        |     decision that arrives as a reviewed commit, never a host edit.
        |   - FAIL CLOSED: both keys must be present lists of positive integers
        |     and must not overlap (one account never holds both halves). A
        |     missing key, a string, zero or negative id, or an overlap admits
        |     NOBODY to any identity-gated action.
        |   - An EMPTY list stops that role only: an empty maker list stops new
        |     submissions while the checker still resolves filed corrections. It
        |     does NOT freeze everything — an emptied CHECKER list leaves already
        |     submitted corrections payment-blocked (D10) until their maker
        |     cancels them, so resolve them first. SUBMIT also requires at least
        |     one designated checker that is a usable (persisted, active) account.
        |   - Cancelling one's OWN correction is not gated here: it is the maker's
        |     de-escalation and the release path that keeps a narrowed list from
        |     stranding an invoice behind the D10 payment block.
        |   - Widening the branch list does NOT widen this list; a new branch or a
        |     new account is a new owner decision.
        */
        'identities_approval' => [
            'decision' => 'D1e',
            'approved_by' => 'Owner',
            'approved_on' => '2026-10-21',
            'source' => 'Owner instruction recorded in HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1',
        ],

        'identities' => [
            'maker_user_ids' => [29],
            'checker_user_ids' => [1],
        ],
    ],

];
