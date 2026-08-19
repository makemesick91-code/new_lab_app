{{--
    Sprint 63.1 — Shared structured odontogram print/PDF template (Daengtisia form).

    Renders the saved structured odontogram data into the Daengtisia paper layout:
    optional clinic header, identity, FDI visual diagram, D/M/F/DMF-T summary,
    legend, and the GIGI | DIAGNOSA | PERAWATAN | DOKTER table with continuation.

    dompdf-safe: the tooth diagram uses HTML <table> cells (NO flexbox). Used by
    both the standalone print (rme.odontograms.print) and the combined visit
    print/PDF bundle (rme.visits.print / rme.visits.pdf).

    Expects:
      $structured   array  — OdontogramPrintFormatter::format() output
      $patientName  string
      $rmNumber     string
      $branchTitle  string
      $showHeader   bool   — render the Daengtisia clinic header (default false)
      $showVisual   bool   — render the FDI diagram + DMF-T + legend (default true)

    Privacy: only patient name + No. RM are emitted. Never KTP/NIK.
--}}
@php
    $showHeader = $showHeader ?? false;
    $showVisual = $showVisual ?? true;
    $jawRows    = $structured['jaw_rows'] ?? [];
    $dmft       = $structured['dmft'] ?? ['D' => 0, 'M' => 0, 'F' => 0, 'DMFT' => 0];
    $legend     = $structured['legend'] ?? [];
    $tableRows  = $structured['table_rows'] ?? [];

    $renderJaw = function (array $row) {
        $html = '<table class="odo-jaw"><tr>';
        foreach ($row['right'] ?? [] as $cell) {
            $html .= '<td class="odo-tooth '.$cell['css'].'">'.$cell['number'].'</td>';
        }
        $html .= '<td class="odo-mid"></td>';
        foreach ($row['left'] ?? [] as $cell) {
            $html .= '<td class="odo-tooth '.$cell['css'].'">'.$cell['number'].'</td>';
        }
        $html .= '</tr></table>';

        return $html;
    };
@endphp

<style>
    .odo-wrap { font-family: DejaVu Sans, 'Segoe UI', Arial, sans-serif; color: #1a1a1a; }
    .odo-clinic-header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 10px; }
    .odo-clinic-header .odo-logo { height: 44px; width: auto; max-width: 150px; vertical-align: middle; }
    .odo-clinic-header .odo-clinic-text { display: inline-block; vertical-align: middle; padding-left: 12px; }
    .odo-clinic-header h2 { font-size: 16px; font-weight: 700; color: #111827; margin: 0; }
    .odo-clinic-meta { font-size: 9px; color: #4b5563; font-style: italic; margin-top: 2px; }

    .odo-doc-title { text-align: center; font-size: 13px; font-weight: 700; text-transform: uppercase; color: #111827; margin: 6px 0 8px; }

    .odo-identity { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .odo-identity td { border: 1px solid #111827; padding: 5px 9px; font-size: 11px; }
    .odo-identity .odo-lbl { font-weight: 700; }

    .odo-section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #374151; margin: 10px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #e5e7eb; }

    /* Tooth diagram — table layout (dompdf-safe, no flexbox) */
    .odo-visual { text-align: center; margin-bottom: 10px; }
    .odo-side-labels { width: 100%; border-collapse: collapse; margin-bottom: 2px; }
    .odo-side-labels td { font-size: 9px; color: #6b7280; }
    .odo-side-labels .odo-side-r { text-align: left; }
    .odo-side-labels .odo-side-l { text-align: right; }
    .odo-jaw { border-collapse: separate; border-spacing: 2px; margin: 0 auto; }
    .odo-jaw td.odo-tooth { width: 24px; height: 24px; border: 1px solid #d1d5db; font-size: 9px; font-weight: 700; text-align: center; vertical-align: middle; }
    .odo-jaw td.odo-mid { width: 8px; border: none; }
    .odo-divider { border-top: 2px dashed #d1d5db; margin: 5px auto; width: 70%; }

    .odo-t-default      { background: #fff;    color: #6b7280; border-color: #d1d5db; }
    .odo-t-normal       { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
    .odo-t-caries       { background: #fecaca; color: #7f1d1d; border-color: #f87171; }
    .odo-t-missing      { background: #1f2937; color: #ffffff; border-color: #4b5563; }
    .odo-t-filling      { background: #bfdbfe; color: #1e3a8a; border-color: #60a5fa; }
    .odo-t-crown        { background: #fde68a; color: #78350f; border-color: #fcd34d; }
    .odo-t-root_treated { background: #bae6fd; color: #0c4a6e; border-color: #38bdf8; }

    /* DMF-T tally */
    .odo-dmft { width: 100%; border-collapse: collapse; margin: 8px 0; }
    .odo-dmft td { border: 1px solid #d1d5db; padding: 5px 8px; font-size: 11px; text-align: center; }
    .odo-dmft .odo-v { font-weight: 700; }

    /* Legend */
    .odo-legend { width: 100%; border-collapse: collapse; background: #f9fafb; border: 1px solid #e5e7eb; margin-bottom: 10px; }
    .odo-legend td { padding: 5px 8px; font-size: 9px; color: #374151; }
    .odo-legend .odo-swatch { display: inline-block; width: 10px; height: 10px; border: 1px solid #9ca3af; vertical-align: middle; margin-right: 4px; }

    /* GIGI | DIAGNOSA | PERAWATAN | DOKTER data table */
    .odo-data { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 12px; }
    .odo-data thead { display: table-header-group; }
    .odo-data th, .odo-data td { border: 1px solid #111827; padding: 4px 7px; vertical-align: top; }
    .odo-data th { background: #f3f4f6; text-align: center; font-weight: 700; color: #111827; text-transform: uppercase; font-size: 9px; }
    .odo-data tr { page-break-inside: avoid; }
    .odo-data .odo-gigi { text-align: center; font-weight: 700; width: 48px; }
    .odo-data .odo-repeat th { background: #fff; text-align: center; font-size: 11px; }
    .odo-data .odo-repeat .odo-sub { display: block; font-weight: 600; font-size: 9px; margin-top: 2px; color: #374151; }
    .odo-empty { color: #9ca3af; font-style: italic; font-size: 10px; margin-bottom: 12px; }
</style>

<div class="odo-wrap">

    @if ($showHeader)
        {{-- FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-01) — the clinic address comes
             from the branch that OWNS this record, never a literal. This partial is
             shared by every branch, so a hardcoded address printed one branch's
             street on another branch's document. A branch with no address recorded
             simply prints no address line; nothing is invented. --}}
        @php
            $branchAddress = trim((string) ($branchAddress ?? ''));
            $branchPhone = trim((string) ($branchPhone ?? ''));
        @endphp
        <div class="odo-clinic-header">
            <x-brand.daengtisia-logo class="odo-logo" />
            <span class="odo-clinic-text">
                <h2>KLINIK GIGI DAENGTISIA</h2>
                @if ($branchAddress !== '' || $branchPhone !== '')
                    <div class="odo-clinic-meta">
                        @if ($branchAddress !== ''){{ $branchAddress }}@endif
                        @if ($branchAddress !== '' && $branchPhone !== '')<br>@endif
                        @if ($branchPhone !== '')Telp/WA {{ $branchPhone }}@endif
                    </div>
                @endif
            </span>
        </div>
    @endif

    @if ($showVisual)
        <div class="odo-doc-title">ODONTOGRAM GIGI PASIEN {{ $branchTitle }}</div>

        <table class="odo-identity">
            <tr>
                <td><span class="odo-lbl">Nama :</span> {{ $patientName }}</td>
                <td><span class="odo-lbl">No. RM :</span> {{ $rmNumber }}</td>
            </tr>
        </table>

        {{-- FDI visual diagram (auto-generated from saved status; no drawing) --}}
        <div class="odo-visual">
            <table class="odo-side-labels">
                <tr>
                    <td class="odo-side-r">KANAN / RIGHT</td>
                    <td class="odo-side-l">KIRI / LEFT</td>
                </tr>
            </table>
            {!! $renderJaw($jawRows['upper_permanent'] ?? ['right' => [], 'left' => []]) !!}
            {!! $renderJaw($jawRows['upper_primary'] ?? ['right' => [], 'left' => []]) !!}
            <div class="odo-divider"></div>
            {!! $renderJaw($jawRows['lower_primary'] ?? ['right' => [], 'left' => []]) !!}
            {!! $renderJaw($jawRows['lower_permanent'] ?? ['right' => [], 'left' => []]) !!}
        </div>

        {{-- Jumlah D / M / F / DMF-T --}}
        <table class="odo-dmft">
            <tr>
                <td>Jumlah D = <span class="odo-v">{{ $dmft['D'] }}</span></td>
                <td>Jumlah M = <span class="odo-v">{{ $dmft['M'] }}</span></td>
                <td>Jumlah F = <span class="odo-v">{{ $dmft['F'] }}</span></td>
                <td>Jumlah DMF-T = <span class="odo-v">{{ $dmft['DMFT'] }}</span></td>
            </tr>
        </table>

        {{-- Legend --}}
        <table class="odo-legend">
            <tr>
                @foreach ($legend as $item)
                    <td><span class="odo-swatch {{ $item['css'] }}"></span>{{ $item['label'] }}</td>
                @endforeach
            </tr>
        </table>
    @endif

    {{-- GIGI | DIAGNOSA | PERAWATAN | DOKTER (header repeats on continuation pages) --}}
    <div class="odo-section-title">Hasil Odontogram yang Dipilih</div>
    @if (! empty($tableRows))
        <table class="odo-data">
            <thead>
                <tr class="odo-repeat">
                    <th colspan="4">
                        ODONTOGRAM GIGI PASIEN {{ $branchTitle }}
                        <span class="odo-sub">Nama : {{ $patientName }} &nbsp;&nbsp; No. RM : {{ $rmNumber }}</span>
                    </th>
                </tr>
                <tr>
                    <th class="odo-gigi">GIGI</th>
                    <th>DIAGNOSA</th>
                    <th>PERAWATAN</th>
                    <th style="width:120px">DOKTER</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tableRows as $row)
                    <tr>
                        <td class="odo-gigi">{{ $row['gigi'] }}</td>
                        <td>{{ $row['diagnosa'] !== '' ? $row['diagnosa'] : '—' }}</td>
                        <td>{{ $row['perawatan'] !== '' ? $row['perawatan'] : '—' }}</td>
                        <td>{{ $row['dokter'] !== '' ? $row['dokter'] : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="odo-empty">Belum ada kondisi odontogram yang dipilih.</p>
    @endif

</div>
