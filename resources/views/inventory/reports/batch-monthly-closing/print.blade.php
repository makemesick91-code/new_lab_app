<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Closing Bulanan Governance Batch</title>
    <style>
        body { font-family: Figtree, Arial, sans-serif; font-size: 12px; color: #111827; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 24px 0 8px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .summary td { font-weight: 600; }
        .signatures { margin-top: 48px; width: 100%; }
        .signatures td { border: none; width: 33%; padding-top: 48px; text-align: center; }
        .warning { background: #fffbeb; border: 1px solid #fcd34d; padding: 8px; margin-bottom: 16px; }
        @media print { .no-print { display: none; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 16px;">
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <h1>Closing Bulanan Governance Batch</h1>
    <p class="meta">
        Periode: {{ $periodLabel }} ({{ $filters['date_from'] }} s/d {{ $filters['date_to'] }})
        @if ($selectedBranchId)
            · Cabang: {{ $branchOptions->firstWhere('id', $selectedBranchId)?->name ?? $selectedBranchId }}
        @elseif ($scope['cross_branch'] ?? false)
            · Semua cabang yang diizinkan
        @endif
        <br>Dicetak: {{ format_datetime_id($generatedAt) }} oleh {{ $printedBy?->name ?? 'Sistem' }}
    </p>

    <div class="warning">
        Closing pack ini bersifat audit/read-only. Tidak mengubah stok. Pengurangan stok tetap hanya melalui movement ledger resmi.
    </div>

    <table class="summary">
        <thead>
            <tr>
                <th>Batch Kedaluwarsa</th>
                <th>Batch Akan Kedaluwarsa</th>
                <th>Action Log</th>
                <th>Request Disposal</th>
                <th>Menunggu Approval</th>
                <th>Adjustment Dicatat</th>
                <th>Qty Diajukan</th>
                <th>Qty Adjustment</th>
                <th>Movement Tertaut</th>
                <th>Anomali</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ format_number_id((int) $summary['total_expired_batches_with_positive_stock']) }}</td>
                <td>{{ format_number_id((int) $summary['total_near_expiry_batches_with_positive_stock']) }}</td>
                <td>{{ format_number_id((int) $summary['total_action_logs']) }}</td>
                <td>{{ format_number_id((int) $summary['total_disposal_requests']) }}</td>
                <td>{{ format_number_id((int) $summary['pending_approval_requests']) }}</td>
                <td>{{ format_number_id((int) $summary['adjustment_recorded_requests']) }}</td>
                <td>{{ format_quantity_id((float) $summary['total_quantity_requested']) }}</td>
                <td>{{ format_quantity_id((float) $summary['total_quantity_adjustment_recorded']) }}</td>
                <td>{{ format_number_id((int) $summary['movement_linked_requests']) }}</td>
                <td>{{ format_number_id((int) $summary['exception_count']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>A. Ringkasan Risiko Expiry</h2>
    <table>
        <thead>
            <tr>
                @if ($scope['cross_branch'] ?? false)<th>Cabang</th>@endif
                <th>Produk</th><th>Batch</th><th>Expiry</th><th>Status</th><th>Lokasi</th><th>Qty Ledger</th><th>Latest Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($expiryRiskRows as $row)
                <tr>
                    @if ($scope['cross_branch'] ?? false)<td>{{ $row['branch']?->name ?? '—' }}</td>@endif
                    <td>{{ $row['product']?->name ?? '—' }}</td>
                    <td>{{ $row['batch']?->batch_number ?? '—' }}</td>
                    <td>{{ $row['expiry_date'] ?? '—' }}</td>
                    <td>{{ $row['expiry_label'] }}</td>
                    <td>{{ $row['location_name'] }}</td>
                    <td>{{ format_quantity_id($row['ledger_qty']) }}</td>
                    <td>{{ $row['latest_action']?->actionTypeLabel() ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 8 : 7 }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>B. Ringkasan Action Log</h2>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                @if ($scope['cross_branch'] ?? false)<th>Cabang</th>@endif
                <th>Produk</th><th>Batch</th><th>Action</th><th>Note</th><th>Actor</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($actionLogRows as $log)
                <tr>
                    <td>{{ format_datetime_id($log->acted_at) }}</td>
                    @if ($scope['cross_branch'] ?? false)<td>{{ $log->branch?->name ?? '—' }}</td>@endif
                    <td>{{ $log->batch?->product?->name ?? '—' }}</td>
                    <td>{{ $log->batch?->batch_number ?? '—' }}</td>
                    <td>{{ $log->actionTypeLabel() }}</td>
                    <td>{{ $log->note ?? '—' }}</td>
                    <td>{{ $log->actor?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 7 : 6 }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>C. Disposal / Return / Adjustment</h2>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                @if ($scope['cross_branch'] ?? false)<th>Cabang</th>@endif
                <th>Produk</th><th>Batch</th><th>Jenis</th><th>Status</th><th>Qty</th><th>Evidence</th><th>Movement</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($disposalRows as $request)
                <tr>
                    <td>{{ format_datetime_id($request->submitted_at ?? $request->created_at) }}</td>
                    @if ($scope['cross_branch'] ?? false)<td>{{ $request->branch?->name ?? '—' }}</td>@endif
                    <td>{{ $request->product?->name ?? '—' }}</td>
                    <td>{{ $request->batch?->batch_number ?? '—' }}</td>
                    <td>{{ $request->requestTypeLabel() }}</td>
                    <td>{{ $request->statusLabel() }}</td>
                    <td>{{ format_quantity_id((float) $request->quantity_requested) }}</td>
                    <td>{{ $request->evidence_reference ?? '—' }}</td>
                    <td>{{ $request->movement?->movement_type ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 9 : 8 }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>D. Ledger Evidence</h2>
    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                @if ($scope['cross_branch'] ?? false)<th>Cabang</th>@endif
                <th>Produk</th><th>Batch</th><th>Type</th><th>Qty Out</th><th>Reference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($ledgerEvidenceRows as $row)
                <tr>
                    <td>{{ $row['movement']->movement_date }}</td>
                    @if ($scope['cross_branch'] ?? false)<td>{{ $row['branch']?->name ?? '—' }}</td>@endif
                    <td>{{ $row['product']?->name ?? '—' }}</td>
                    <td>{{ $row['batch']?->batch_number ?? '—' }}</td>
                    <td>{{ $row['movement']->movement_type }}</td>
                    <td>{{ format_quantity_id((float) $row['movement']->quantity_out) }}</td>
                    <td>{{ $row['movement']->reference_number ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 7 : 6 }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>E. Follow-up / Exceptions</h2>
    <table>
        <thead>
            <tr>
                <th>Jenis</th>
                @if ($scope['cross_branch'] ?? false)<th>Cabang</th>@endif
                <th>Produk</th><th>Batch</th><th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($exceptionRows as $exception)
                <tr>
                    <td>{{ $exception['type'] }}</td>
                    @if ($scope['cross_branch'] ?? false)<td>{{ $exception['branch']?->name ?? '—' }}</td>@endif
                    <td>{{ $exception['product']?->name ?? '—' }}</td>
                    <td>{{ $exception['batch']?->batch_number ?? '—' }}</td>
                    <td>{{ $exception['label'] }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ ($scope['cross_branch'] ?? false) ? 5 : 4 }}">Tidak ada anomali.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>F. Checklist Closing</h2>
    <table>
        <thead><tr><th>Item</th><th>Centang</th></tr></thead>
        <tbody>
            @foreach ($checklist as $item)
                <tr>
                    <td>{{ $item['label'] }}</td>
                    <td style="width: 80px;">&nbsp;</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>Admin Warehouse<br><br>________________________</td>
            <td>Supervisor<br><br>________________________</td>
            <td>Owner<br><br>________________________</td>
        </tr>
    </table>
</body>
</html>
