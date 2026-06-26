@php
    /**
     * Shared "Hasil Odontogram yang Dipilih" selected-results table for print output.
     *
     * Expects:
     *   $odontogram — App\Modules\Odontogram\Models\Odontogram|null
     *
     * Renders the subsection title, the merged selected-results table, and safe
     * empty states. Used by the combined Cetak Rekam Medis bundle (print-body)
     * so odontogram results appear without opening the separate Cetak Odontogram.
     */
    // Hotfix Sprint 60.3 — Daengtisia columns GIGI / DIAGNOSA / PERAWATAN / DOKTER.
    $statusLabels = [
        'caries' => 'Karies',
        'missing' => 'Hilang',
        'filling' => 'Tambalan',
        'crown' => 'Crown',
        'root_treated' => 'PSA',
        'normal' => 'Normal',
    ];
    // Legacy per-tooth clinical signs (Sprint 20) — still rendered in DIAGNOSA detail.
    $conditionLabels = [
        'caries' => 'Karies',
        'missing' => 'Hilang',
        'crown' => 'Crown',
        'root_treated' => 'PSA',
        'mobility' => 'Goyang',
        'impaction' => 'Impaksi',
        'filling' => 'Tambalan',
    ];

    $teethData = $odontogram?->tooth_map_payload['teeth'] ?? [];
    $markedTeeth = array_filter($teethData, fn ($td) =>
        ! empty($td['status'])
        || ! empty($td['conditions'])
        || (isset($td['note']) && $td['note'] !== '' && $td['note'] !== null)
        || (isset($td['additional_condition']) && $td['additional_condition'] !== '' && $td['additional_condition'] !== null)
        || (isset($td['additional_note']) && $td['additional_note'] !== '' && $td['additional_note'] !== null)
        || (isset($td['dokter']) && $td['dokter'] !== '' && $td['dokter'] !== null)
    );
    ksort($markedTeeth, SORT_NATURAL);
@endphp

<div class="section-title" style="font-size:11px; margin-top:10px; border-bottom:none; padding-bottom:0;">
    Hasil Odontogram yang Dipilih
</div>

@if (count($markedTeeth) > 0)
    <table class="odonto-table" style="margin-top:6px;">
        <thead>
            <tr>
                <th style="width:50px">GIGI</th>
                <th style="width:150px">DIAGNOSA</th>
                <th>PERAWATAN</th>
                <th style="width:120px">DOKTER</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($markedTeeth as $toothNum => $td)
                @php
                    $diagnosaParts = [];
                    $statusLabel = $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '');
                    if ($statusLabel !== '') {
                        $diagnosaParts[] = $statusLabel;
                    }
                    if (isset($td['additional_condition']) && $td['additional_condition'] !== '') {
                        $diagnosaParts[] = $td['additional_condition'];
                    }
                    // Legacy per-tooth clinical signs + note preserved within DIAGNOSA detail.
                    if (! empty($td['conditions']) && is_array($td['conditions'])) {
                        foreach ($td['conditions'] as $cond) {
                            $diagnosaParts[] = $conditionLabels[$cond] ?? $cond;
                        }
                    }
                    if (isset($td['note']) && $td['note'] !== '') {
                        $diagnosaParts[] = $td['note'];
                    }
                    $diagnosa = implode(' — ', $diagnosaParts);
                    $perawatan = (isset($td['additional_note']) && $td['additional_note'] !== '') ? $td['additional_note'] : '';
                    $dokter = (isset($td['dokter']) && $td['dokter'] !== '') ? $td['dokter'] : '';
                @endphp
                <tr>
                    <td><strong>{{ $toothNum }}</strong></td>
                    <td>{{ $diagnosa !== '' ? $diagnosa : '—' }}</td>
                    <td>{{ $perawatan !== '' ? $perawatan : '—' }}</td>
                    <td>{{ $dokter !== '' ? $dokter : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p style="margin-top:6px;color:#9ca3af;font-style:italic;font-size:11px;">
        Belum ada kondisi odontogram yang dipilih.
    </p>
@endif
