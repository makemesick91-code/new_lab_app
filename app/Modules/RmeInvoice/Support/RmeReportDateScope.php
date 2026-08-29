<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Support\Clinical\ClinicalClock;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * REVISION-RME-REPORTS-TODAY-DEFAULT-1 — the SINGLE authority for "which days
 * does an RME report show?".
 *
 * ── The defect this closes ────────────────────────────────────────────────
 *
 * Both RME reports applied their date predicate only when the operator had
 * supplied one (`->when($request->filled('date_from'), ...)`). Opening
 * "Laporan Pasien RME" or "Laporan Pembayaran RME" therefore returned the whole
 * archive — every visit and every payment the branch had ever recorded — and
 * the same was true of the CSV export and the print view. An Admin Klinik or
 * Kasir who only ever wants "who is here today" had to filter their way down to
 * it, and a stray click on Export produced a full historical extract.
 *
 * The period is now always bounded. Absent or unusable input resolves to the
 * current CLINICAL day; history is opt-in and requires an explicit, valid date.
 *
 * ── Fail closed, never fail open ──────────────────────────────────────────
 *
 * A malformed bound ("not-a-date", "29/08/2026", "2026-13-45", an empty string)
 * is NOT authorisation to widen the period. Each bound is validated
 * independently and an unusable one is simply absent; when that leaves no bound
 * at all the period collapses back to today. There is no input that turns an
 * unfiltered report into an all-history report.
 *
 * Dates are parsed STRICTLY. `Carbon::parse('2026-13-45')` silently rolls into
 * 2027-02-14 — a rolled date is a different clinical period than the one the
 * operator typed, so a value that does not round-trip through `!Y-m-d` is
 * rejected rather than reinterpreted.
 *
 * ── What this class is NOT ────────────────────────────────────────────────
 *
 * It answers only "which days". Branch authority stays with
 * {@see RmeWorkingBranchScope}: a date
 * filter widens the period, NEVER the set of branches. The two predicates are
 * ANDed in every report query, so history is still only ever the viewer's own
 * authorised history.
 *
 * "Today" is the clinical calendar day from {@see ClinicalClock}
 * (Asia/Makassar), not the UTC storage day. Between 16:00 and 24:00 UTC it is
 * already tomorrow in the clinic, and a UTC-anchored default would show the
 * wrong day's patients for eight hours of every day.
 */
final class RmeReportDateScope
{
    /**
     * Where the per-request resolution is memoized.
     *
     * The memo lives on the REQUEST, never on the scope or the controller.
     * Laravel caches the controller instance on the Route object and the Router
     * is a singleton, so a `private ?RmeReportDateRange $range` property on the
     * controller survives between requests wherever the application is not torn
     * down per request — a long-lived worker, and every multi-request test.
     * That would pin the report to the first request's day and silently serve a
     * stale "today" after the clinical midnight. The request is the only object
     * whose lifetime is exactly one report render.
     */
    private const REQUEST_ATTRIBUTE = 'rme_report_date_range';

    public function __construct(private readonly ClinicalClock $clock) {}

    /**
     * Normalize a report request into the period its queries must use.
     *
     * Resolved once per request and reused, so a request that spans a clinical
     * midnight cannot render its rows for one day and its totals for the next.
     */
    public function resolve(Request $request): RmeReportDateRange
    {
        $memoized = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($memoized instanceof RmeReportDateRange) {
            return $memoized;
        }

        $from = $this->normalizeBound($request->input('date_from'));
        $to = $this->normalizeBound($request->input('date_to'));

        $range = ($from === null && $to === null)
            ? RmeReportDateRange::defaultToday($this->clock->todayString())
            : RmeReportDateRange::explicit($from, $to);

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $range);

        return $range;
    }

    /**
     * A single date bound, or null when the operator did not supply a usable
     * one. Strict `Y-m-d`; anything that does not round-trip is discarded.
     */
    public function normalizeBound(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false) {
            return null;
        }

        // Reject anything PHP had to reinterpret to accept — "2026-13-45"
        // parses, but as a different day than the operator asked for.
        return $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
