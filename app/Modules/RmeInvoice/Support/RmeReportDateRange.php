<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use DateTimeImmutable;

/**
 * REVISION-RME-REPORTS-TODAY-DEFAULT-1 — the normalized reporting period for
 * the RME patient and payment reports.
 *
 * An immutable answer to "which clinical days is this report showing?", built
 * once per request by {@see RmeReportDateScope} and then shared by the on-screen
 * list, the totals, the CSV export, the print view and the filter summary. One
 * value object is what makes those five surfaces provably agree: the screen can
 * never say "today" while the export quietly returns the whole archive.
 *
 * `from`/`to` are inclusive `Y-m-d` CLINICAL calendar dates (never instants) and
 * are compared against `trx_clinic_visits.visit_date`. A null bound means the
 * operator explicitly asked for an open-ended period on that side; both bounds
 * are never null at once, because an absent filter resolves to today.
 */
final class RmeReportDateRange
{
    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    private function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly bool $isDefaultToday,
    ) {}

    /**
     * The default period: the current clinical day only.
     *
     * This is what an operator sees on a bare report URL, after a reset, and
     * whenever the supplied filter carried no usable date at all.
     */
    public static function defaultToday(string $today): self
    {
        return new self($today, $today, true);
    }

    /**
     * A period the operator explicitly asked for. At least one bound is set —
     * the caller resolves an all-null filter to {@see self::defaultToday()}.
     */
    public static function explicit(?string $from, ?string $to): self
    {
        return new self($from, $to, false);
    }

    /**
     * A human-readable Indonesian period label, rendered on every report and
     * export surface so the active period is never implicit.
     */
    public function label(): string
    {
        if ($this->isDefaultToday) {
            return 'Hari Ini — '.$this->formatDate((string) $this->from);
        }

        if ($this->from !== null && $this->to !== null) {
            return $this->from === $this->to
                ? $this->formatDate($this->from)
                : $this->formatDate($this->from).' — '.$this->formatDate($this->to);
        }

        if ($this->from !== null) {
            return 'Sejak '.$this->formatDate($this->from);
        }

        return 'Sampai '.$this->formatDate((string) $this->to);
    }

    private function formatDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false) {
            return $date;
        }

        return (int) $parsed->format('j')
            .' '.(self::MONTHS[(int) $parsed->format('n')] ?? $parsed->format('F'))
            .' '.$parsed->format('Y');
    }
}
