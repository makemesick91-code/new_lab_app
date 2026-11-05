<?php

namespace App\Support\Routing;

use Illuminate\Support\Facades\Route;

/**
 * DMS-RME-REVISION-PROGRAM-1 / R-01 — route parameters that name a bigint
 * database key are constrained before they can reach a query.
 *
 * Implicit model binding runs `where "id" = '<segment>'`. On PostgreSQL a
 * segment that is not an integer (`abc`, a pasted `{VISIT_ID}` placeholder)
 * raises SQLSTATE 22P02, and a number beyond bigint raises 22003, so the
 * request answered HTTP 500 and wrote an ERROR line instead of a 404. A route
 * constraint makes the router reject such a segment before any middleware,
 * binding or query runs.
 *
 * PATTERN accepts 1 to 18 digits with no sign and no leading zero. Every key
 * an identity column can issue is in that range, and the largest accepted
 * value (18 nines) is below the bigint ceiling of 9,223,372,036,854,775,807,
 * so PostgreSQL can always cast what the router lets through.
 *
 * Patterns are applied when a route is DEFINED, so register() must run before
 * the route files load: AppServiceProvider::boot() is that place (Laravel's
 * documented location for global patterns), and the compiled collection that
 * route:cache writes carries the constraint with it.
 */
final class DatabaseRouteKey
{
    public const PATTERN = '[1-9][0-9]{0,17}';

    /**
     * Parameters constrained globally. Each is a domain-specific name, and
     * every route carrying it binds a bigint `id()` primary key:
     *
     *   clinicVisit      DMS-RME-REVISION-PROGRAM-1 / R-01
     *   rmeInvoice       DMS-RME-REVISION-PROGRAM-2 / R-03
     *   medicalRecord    DMS-RME-REVISION-PROGRAM-2 / R-04
     *   rmePrescription  DMS-RME-REVISION-PROGRAM-2 / R-05
     *   consent          DMS-RME-REVISION-PROGRAM-2 / R-06
     *   odontogram       DMS-RME-REVISION-PROGRAM-2 / R-07
     *
     * A GENERIC name (`visit`, `patient`, `record`, `page`) is never added
     * here: it may later name another identifier type (the oversight lookup
     * already resolves a visit by its string visit number), and a global
     * numeric pattern would silently 404 that route. Constrain a generic name
     * per route with `->where('<name>', DatabaseRouteKey::PATTERN)` instead
     * (R-08), never with `whereNumber`, whose `[0-9]+` admits overflow.
     *
     * Extending this list needs owner approval (rule 222).
     *
     * @var list<string>
     */
    public const CONSTRAINED_PARAMETERS = [
        'clinicVisit',
        'rmeInvoice',
        'medicalRecord',
        'rmePrescription',
        'consent',
        'odontogram',
    ];

    public static function register(): void
    {
        foreach (self::CONSTRAINED_PARAMETERS as $parameter) {
            Route::pattern($parameter, self::PATTERN);
        }
    }
}
