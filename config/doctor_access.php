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
| or duplicated the first time a second one needed the same bound.
|
| NO env() CALL ANYWHERE IN THIS FILE, on purpose. A bound an operator can move
| from the environment of the machine they are already on is not a bound. There
| is also nothing risky here to declare, so this file never interacts with the
| FLAG-RISKY-DEFAULT-OFF gate — the flag that arms the capability lives in
| config/feature_flags.php and is read only through FeatureFlagService.
|
*/

return [

    'reason' => [

        /*
         * Every decision that ends somebody else's login session carries a
         * written reason.
         *
         * A SESSION NOBODY CAN EXPLAIN ENDING IS NOT AN OPERATIONAL ACTION, it
         * is an unexplained logout — and a doctor mid-consultation who is
         * logged out deserves a trail that says who did it and why. These two
         * numbers are quoted directly in the console command's own refusals, so
         * the operator is told the same bound the server enforces.
         *
         * `min_length` is a real floor rather than a non-empty check: 'x' and
         * 'asdf' pass a non-empty check and explain nothing. `max_length`
         * bounds what lands in an audit payload.
         */
        'min_length' => 10,
        'max_length' => 1000,
    ],
];
