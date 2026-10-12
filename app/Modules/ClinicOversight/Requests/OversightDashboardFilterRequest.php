<?php

namespace App\Modules\ClinicOversight\Requests;

use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187) — the filter of the
 * Finance & Data Analyst dashboard (page, polling feed, CSV, print).
 *
 * Extends the shared oversight filter SHAPE and adds the date range. The other
 * oversight surfaces keep using OversightFilterRequest and are unaffected.
 *
 * THE ONE COMPATIBILITY RULE for the date parameters:
 *   1. none of start_date / end_date / date  -> the clinical (WITA) today;
 *   2. start_date AND end_date               -> that inclusive range;
 *   3. only one of start_date / end_date     -> REJECTED (never guessed);
 *   4. legacy `date` alone                   -> that single day (old links
 *                                               and bookmarks keep their meaning);
 *      `date` together with start/end        -> REJECTED.
 * A reversed range is rejected, never exchanged. Span and calendar bounds are
 * the ClinicalDateRange limits.
 *
 * Authorization is unchanged: route permission middleware runs before this
 * request is resolved (so a caller without access gets 403, never a 422 that
 * would answer for the dates), and `branch_id` stays a narrowing hint only.
 */
class OversightDashboardFilterRequest extends OversightFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $min = ClinicalDateRange::MIN_DATE;
        $max = ClinicalDateRange::MAX_DATE;

        // ONLY the keys the dashboard uses. The list-page filters of the parent
        // (visit_date_from/to, status, search, room…) are deliberately NOT
        // inherited: the dashboard ignores them, and the parent's field-reference
        // rule `visit_date_to => after_or_equal:visit_date_from` turns an array
        // `visit_date_from` into a TypeError (HTTP 500). An unvalidated key never
        // reaches filters(), so it can neither break nor steer this surface.
        return [
            'branch_id' => parent::rules()['branch_id'],
            'date' => ['nullable', 'bail', 'date_format:Y-m-d', "after_or_equal:{$min}", "before_or_equal:{$max}", 'prohibits:start_date,end_date'],
            'start_date' => ['nullable', 'bail', 'required_with:end_date', 'date_format:Y-m-d', "after_or_equal:{$min}", "before_or_equal:{$max}"],
            // Order (end >= start) is checked in withValidator(), NOT with the
            // field-reference rule `after_or_equal:start_date`: that rule feeds
            // the OTHER field's raw value to DateTime, so `start_date[]=…`
            // would raise a TypeError (HTTP 500) instead of a 422.
            'end_date' => ['nullable', 'bail', 'required_with:start_date', 'date_format:Y-m-d', "after_or_equal:{$min}", "before_or_equal:{$max}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $bounds = 'Tanggal harus berada antara 01/01/2000 dan 31/12/2099.';

        return [
            'start_date.required_with' => 'Tanggal mulai wajib diisi bersama tanggal akhir.',
            'end_date.required_with' => 'Tanggal akhir wajib diisi bersama tanggal mulai.',
            'start_date.date_format' => 'Format tanggal mulai harus YYYY-MM-DD.',
            'end_date.date_format' => 'Format tanggal akhir harus YYYY-MM-DD.',
            'date.date_format' => 'Format tanggal harus YYYY-MM-DD.',
            'start_date.after_or_equal' => $bounds,
            'start_date.before_or_equal' => $bounds,
            'end_date.before_or_equal' => $bounds,
            'date.after_or_equal' => $bounds,
            'date.before_or_equal' => $bounds,
            'end_date.after_or_equal' => $bounds,
            'date.prohibits' => 'Parameter tanggal tunggal tidak dapat digabung dengan tanggal mulai/akhir.',
        ];
    }

    /**
     * Order and span, checked only once both dates are individually valid
     * strings (otherwise a field error is already present). A reversed range
     * is REJECTED here — the dates are never exchanged.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $errors = $v->errors();
            if ($errors->has('start_date') || $errors->has('end_date')) {
                return;
            }

            $start = $this->input('start_date');
            $end = $this->input('end_date');
            if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
                return;
            }

            if ($start > $end) {
                $errors->add('end_date', 'Tanggal akhir tidak boleh sebelum tanggal mulai.');

                return;
            }

            if (ClinicalDateRange::inclusiveDayCount($start, $end) > ClinicalDateRange::MAX_DAYS) {
                $errors->add('end_date', 'Rentang tanggal maksimal '.ClinicalDateRange::MAX_DAYS.' hari.');
            }
        });
    }

    /**
     * The resolved range (rule above). Fails closed if a value that passed
     * validation is still not a valid range — never silently falls back to
     * today for a request that asked for something else.
     */
    public function dateRange(): ClinicalDateRange
    {
        $clock = app(ClinicalClock::class);
        $filters = $this->filters();

        if (isset($filters['start_date'], $filters['end_date'])) {
            $range = ClinicalDateRange::tryFrom($filters['start_date'], $filters['end_date'], $clock);
        } elseif (isset($filters['date'])) {
            $range = ClinicalDateRange::singleDay($filters['date'], $clock);
        } else {
            return ClinicalDateRange::today($clock);
        }

        if ($range === null) {
            throw ValidationException::withMessages(['end_date' => 'Rentang tanggal tidak valid.'])
                ->redirectTo($this->getRedirectUrl());
        }

        return $range;
    }

    /**
     * A refused filter on the page goes back to the dashboard itself (never
     * the previous URL, which could be this same invalid request and loop),
     * keeping only a shape-valid branch id — the scope still authorizes it.
     */
    protected function getRedirectUrl(): string
    {
        $branch = $this->query('branch_id');
        $params = is_string($branch) && preg_match('/^[1-9]\d{0,9}$/D', $branch) === 1
            ? ['branch_id' => $branch]
            : [];

        return route('oversight.dashboard', $params);
    }
}
