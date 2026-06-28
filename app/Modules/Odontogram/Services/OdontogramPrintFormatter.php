<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Odontogram\Models\Odontogram;

/**
 * Sprint 63.1 — Structured Odontogram Print Template Conversion.
 *
 * Read-only presentation formatter. Converts the already-saved structured
 * odontogram data (trx_odontograms.tooth_map_payload) into a normalized
 * view-model consumed by the shared Daengtisia print/PDF partial
 * (resources/views/rme/visits/odontogram/partials/structured-print-template.blade.php).
 *
 * Hard rules:
 *  - No DB writes, no mutation of the Odontogram model.
 *  - DMF-T is delegated to Odontogram::dmftCounts() (single source of truth).
 *  - Unknown statuses degrade to the default cell and never inflate DMF-T.
 *  - No KTP/NIK is ever read or emitted here.
 */
class OdontogramPrintFormatter
{
    /**
     * Optional first-page row budget. The continuation page split is normally
     * handled by CSS (repeating <thead> + page-break-inside: avoid), so this is
     * advisory metadata for the template, not a hard cap on rendered rows.
     */
    public const FIRST_PAGE_ROW_BUDGET = 13;

    /**
     * Per-tooth primary status → human label (Daengtisia legend parity).
     */
    private const STATUS_LABELS = [
        'normal' => 'Normal',
        'caries' => 'Karies',
        'missing' => 'Hilang',
        'filling' => 'Tambalan',
        'crown' => 'Crown',
        'root_treated' => 'PSA',
    ];

    /**
     * Legacy Sprint 20 per-tooth clinical signs → label (kept in DIAGNOSA detail).
     */
    private const CONDITION_LABELS = [
        'caries' => 'Karies',
        'missing' => 'Hilang',
        'crown' => 'Crown',
        'root_treated' => 'PSA',
        'mobility' => 'Goyang',
        'impaction' => 'Impaksi',
        'filling' => 'Tambalan',
    ];

    /**
     * Status → dompdf-safe CSS class (prefixed to avoid clashing with host views).
     */
    private const STATUS_CSS = [
        'normal' => 'odo-t-normal',
        'caries' => 'odo-t-caries',
        'missing' => 'odo-t-missing',
        'filling' => 'odo-t-filling',
        'crown' => 'odo-t-crown',
        'root_treated' => 'odo-t-root_treated',
    ];

    /**
     * FDI display order, center → outer, mirroring the Daengtisia paper form.
     */
    private const LAYOUT = [
        'upper_permanent' => [
            'right' => [18, 17, 16, 15, 14, 13, 12, 11],
            'left' => [21, 22, 23, 24, 25, 26, 27, 28],
        ],
        'upper_primary' => [
            'right' => [55, 54, 53, 52, 51],
            'left' => [61, 62, 63, 64, 65],
        ],
        'lower_primary' => [
            'right' => [85, 84, 83, 82, 81],
            'left' => [71, 72, 73, 74, 75],
        ],
        'lower_permanent' => [
            'right' => [48, 47, 46, 45, 44, 43, 42, 41],
            'left' => [31, 32, 33, 34, 35, 36, 37, 38],
        ],
    ];

    /**
     * Build the full structured view-model for printing.
     *
     * @return array{
     *   jaw_rows: array<string, array{right: array<int, array>, left: array<int, array>}>,
     *   dmft: array{D:int, M:int, F:int, DMFT:int},
     *   legend: array<int, array{css:string, label:string}>,
     *   table_rows: array<int, array{gigi:string, diagnosa:string, perawatan:string, dokter:string}>,
     *   has_rows: bool,
     *   first_page_row_budget: int
     * }
     */
    public function format(Odontogram $odontogram): array
    {
        $teeth = $odontogram->tooth_map_payload['teeth'] ?? [];
        if (! is_array($teeth)) {
            $teeth = [];
        }

        $tableRows = $this->buildTableRows($teeth);

        return [
            'jaw_rows' => $this->buildJawRows($teeth),
            'dmft' => $odontogram->dmftCounts(),
            'legend' => $this->legend(),
            'table_rows' => $tableRows,
            'has_rows' => count($tableRows) > 0,
            'first_page_row_budget' => self::FIRST_PAGE_ROW_BUDGET,
        ];
    }

    /**
     * @param  array<string, mixed>  $teeth
     * @return array<string, array{right: array<int, array>, left: array<int, array>}>
     */
    private function buildJawRows(array $teeth): array
    {
        $rows = [];

        foreach (self::LAYOUT as $key => $sides) {
            $rows[$key] = [
                'right' => array_map(fn (int $n) => $this->cell($n, $teeth), $sides['right']),
                'left' => array_map(fn (int $n) => $this->cell($n, $teeth), $sides['left']),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $teeth
     * @return array{number:int, status:?string, css:string}
     */
    private function cell(int $number, array $teeth): array
    {
        $entry = $teeth[(string) $number] ?? null;
        $status = is_array($entry) ? ($entry['status'] ?? null) : null;

        return [
            'number' => $number,
            'status' => $status,
            // Unknown / unset status degrades to the default cell — never crashes.
            'css' => ($status && isset(self::STATUS_CSS[$status])) ? self::STATUS_CSS[$status] : 'odo-t-default',
        ];
    }

    /**
     * Selected rows = teeth carrying a status or any per-row text. One row per
     * tooth (grouped), ascending FDI — matches the paper form and the data model
     * (single status per tooth + free-text addenda).
     *
     * @param  array<string, mixed>  $teeth
     * @return array<int, array{gigi:string, diagnosa:string, perawatan:string, dokter:string}>
     */
    private function buildTableRows(array $teeth): array
    {
        $marked = array_filter($teeth, fn ($td) => is_array($td) && (
            ! empty($td['status'])
            || ! empty($td['conditions'])
            || (isset($td['note']) && $td['note'] !== '' && $td['note'] !== null)
            || (isset($td['additional_condition']) && $td['additional_condition'] !== '' && $td['additional_condition'] !== null)
            || (isset($td['additional_note']) && $td['additional_note'] !== '' && $td['additional_note'] !== null)
            || (isset($td['dokter']) && $td['dokter'] !== '' && $td['dokter'] !== null)
        ));

        ksort($marked, SORT_NATURAL);

        $rows = [];

        foreach ($marked as $toothNum => $td) {
            $rows[] = [
                'gigi' => (string) $toothNum,
                'diagnosa' => $this->diagnosa($td),
                'perawatan' => (isset($td['additional_note']) && $td['additional_note'] !== '') ? (string) $td['additional_note'] : '',
                'dokter' => (isset($td['dokter']) && $td['dokter'] !== '') ? (string) $td['dokter'] : '',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $td
     */
    private function diagnosa(array $td): string
    {
        $parts = [];

        $status = $td['status'] ?? '';
        if ($status !== '' && $status !== null) {
            $parts[] = self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
        }

        if (isset($td['additional_condition']) && $td['additional_condition'] !== '') {
            $parts[] = (string) $td['additional_condition'];
        }

        if (! empty($td['conditions']) && is_array($td['conditions'])) {
            foreach ($td['conditions'] as $cond) {
                if ($cond === null || $cond === '') {
                    continue;
                }
                $parts[] = self::CONDITION_LABELS[$cond] ?? (string) $cond;
            }
        }

        if (isset($td['note']) && $td['note'] !== '') {
            $parts[] = (string) $td['note'];
        }

        return implode(' — ', $parts);
    }

    /**
     * @return array<int, array{css:string, label:string}>
     */
    private function legend(): array
    {
        return [
            ['css' => 'odo-t-caries', 'label' => 'D = Decay (Karies)'],
            ['css' => 'odo-t-missing', 'label' => 'M = Missing (Hilang)'],
            ['css' => 'odo-t-filling', 'label' => 'F = Filling (Tambalan)'],
            ['css' => 'odo-t-crown', 'label' => 'Crown'],
            ['css' => 'odo-t-root_treated', 'label' => 'PSA'],
        ];
    }
}
