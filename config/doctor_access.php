<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — doctor access bounds
|--------------------------------------------------------------------------
|
| NAMED AFTER THE MODULE, not after one capability inside it. The bounds below
| are read by App\Modules\DoctorAccess and by the console surface that drives
| it, and a file named for a single capability would have to be either renamed
| or duplicated the first time a second one needed the same bound. That
| prediction has already come true once: the cover bounds below arrived with the
| branch-lock capability and reuse the same `reason` block rather than standing
| up a second file to say the same two numbers.
|
| NO env() CALL ANYWHERE IN THIS FILE, on purpose. A bound an operator can move
| from the environment of the machine they are already on is not a bound. There
| is also nothing risky here to declare, so this file never interacts with the
| FLAG-RISKY-DEFAULT-OFF gate — the flags that arm these capabilities live in
| config/feature_flags.php and are read only through FeatureFlagService.
|
*/

return [

    'cover' => [

        /*
         * THESE ARE THE BOUNDS THAT MAKE ONE APPROVAL PERMISSION SAFE.
         *
         * `approve_doctor_branch_locks` decides initial assignment, permanent
         * transfer AND temporary cover. Splitting cover into its own permission
         * would look like least privilege and would actually be a privilege
         * escalation by duration: an unbounded cover, renewed, relocates a
         * doctor permanently through the weaker gate without the transfer
         * workflow ever running. `max_days` is the bound that closes that, so it
         * is load-bearing and not decoration.
         *
         * IT IS VALIDATED TWICE: once when the cover is filed, and AGAIN inside
         * the approval transaction. A request filed while the bound was 365 must
         * not become approvable after an operator lowers it to 90 — the second
         * read is what makes lowering the bound take effect on work already in
         * the queue.
         */

        /*
         * The longest temporary cover an approver may grant, in days.
         *
         * Measured on the [starts_at, ends_at) interval itself, not on the
         * distance from now, so a cover scheduled far ahead is bounded by its
         * own length rather than by when somebody got round to filing it.
         */
        'max_days' => 90,

        /*
         * The shortest cover worth granting, in minutes.
         *
         * A zero-length or near-zero-length window would be approved, would
         * release the doctor's session, and would then expire before they
         * finished logging back in — an eviction with no authority behind it.
         */
        'min_minutes' => 30,
    ],

    'reason' => [

        /*
         * Every decision that ends somebody else's login session carries a
         * written reason, and so does every branch request, decision and
         * cancellation.
         *
         * A SESSION NOBODY CAN EXPLAIN ENDING IS NOT AN OPERATIONAL ACTION, it
         * is an unexplained logout — and a doctor mid-consultation who is
         * logged out deserves a trail that says who did it and why. These two
         * numbers are quoted directly in the console command's own refusals and
         * in the branch FormRequest messages, so the operator is told the same
         * bound the server enforces.
         *
         * `min_length` is a real floor rather than a non-empty check: 'x' and
         * 'asdf' pass a non-empty check and explain nothing. `max_length`
         * bounds what lands in an audit payload.
         */
        'min_length' => 10,
        'max_length' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk device authorization (PR-C)
    |--------------------------------------------------------------------------
    |
    | Ceilings for the fleet-wide provisioning tool. They exist so that the tool
    | REFUSES rather than degrades: a bulk writer that quietly handles an estate
    | larger than the one it was reviewed against is exactly the blast radius
    | the dry-run default and the plan digest exist to bound. Refusing sends an
    | operator to look at why the estate grew; paginating or sampling would turn
    | the bound into a suggestion.
    |
    | Literal integers, no env(), for the reason stated at the top of this file.
    | The pilot today is 15 doctors x 3 devices = 45 pairs; these leave room for
    | the estate to grow without being numbers nobody would notice exceeding.
    */
    'bulk_authorization' => [
        'max_estate_devices' => 500,
        'max_pairs' => 10000,
    ],
];
