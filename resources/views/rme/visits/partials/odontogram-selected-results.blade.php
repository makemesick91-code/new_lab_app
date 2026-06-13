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
    $statusLabels = [
        'caries' => 'Karies',
        'missing' => 'Cabut/Missing',
        'crown' => 'Mahkota',
        'root_treated' => 'Perawatan Saluran Akar',
        'mobility' => 'Goyang',
        'impaction' => 'Impaksi',
        'filling' => 'Tambalan',
        'normal' => 'Normal',
    ];
    $conditionLabels = $statusLabels;

    $teethData = $odontogram?->tooth_map_payload['teeth'] ?? [];
    $markedTeeth = array_filter($teethData, fn ($td) =>
        ! empty($td['status'])
        || ! empty($td['conditions'])
        || (isset($td['note']) && $td['note'] !== '' && $td['note'] !== null)
    );
    ksort($markedTeeth);
@endphp

<div class="section-title" style="font-size:11px; margin-top:10px; border-bottom:none; padding-bottom:0;">
    Hasil Odontogram yang Dipilih
</div>

@if (count($markedTeeth) > 0)
    <table class="odonto-table" style="margin-top:6px;">
        <thead>
            <tr>
                <th style="width:34px">No</th>
                <th style="width:60px">Gigi / Area</th>
                <th style="width:105px">Kondisi Odontogram</th>
                <th>Tanda Klinis / Kondisi Tambahan</th>
                <th>Catatan Gigi / Catatan Tambahan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($markedTeeth as $toothNum => $td)
                @php
                    $signs = [];
                    if (! empty($td['conditions']) && is_array($td['conditions'])) {
                        foreach ($td['conditions'] as $cond) {
                            $signs[] = $conditionLabels[$cond] ?? $cond;
                        }
                    }
                    if (isset($td['additional_condition']) && $td['additional_condition'] !== '') {
                        $signs[] = $td['additional_condition'];
                    }

                    $notes = [];
                    if (isset($td['note']) && $td['note'] !== '') {
                        $notes[] = $td['note'];
                    }
                    if (isset($td['additional_note']) && $td['additional_note'] !== '') {
                        $notes[] = $td['additional_note'];
                    }
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><strong>{{ $toothNum }}</strong></td>
                    <td>{{ $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '—') }}</td>
                    <td>{{ count($signs) ? implode(', ', $signs) : '—' }}</td>
                    <td>{{ count($notes) ? implode(' — ', $notes) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p style="margin-top:6px;color:#9ca3af;font-style:italic;font-size:11px;">
        Belum ada kondisi odontogram yang dipilih.
    </p>
@endif
