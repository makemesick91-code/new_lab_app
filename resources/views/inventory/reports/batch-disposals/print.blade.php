<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Disposal & Adjustment Batch</title>
    <style>
        body { font-family: Figtree, Arial, sans-serif; font-size: 12px; color: #111827; margin: 24px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .meta { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .summary td { font-weight: 600; }
        .signatures { margin-top: 48px; width: 100%; }
        .signatures td { border: none; width: 50%; padding-top: 48px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 16px;">
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <h1>Laporan Disposal & Adjustment Batch</h1>
    <p class="meta">
        Audit batch dari action log, permintaan disposal, approval, hingga movement ADJUSTMENT_OUT.<br>
        Periode: {{ $filters['date_from'] ?? '—' }} s/d {{ $filters['date_to'] ?? '—' }}
        @if ($selectedBranchId)
            · Cabang: {{ $branchOptions->firstWhere('id', $selectedBranchId)?->name ?? $selectedBranchId }}
        @elseif ($scope['cross_branch'] ?? false)
            · Semua cabang yang diizinkan
        @endif
        <br>Dicetak: {{ format_datetime_id($generatedAt) }} oleh {{ $printedBy?->name ?? 'Sistem' }}
    </p>

    <table class="summary">
        <thead>
            <tr>
                <th>Total Request</th>
                <th>Menunggu Approval</th>
                <th>Disetujui</th>
                <th>Ditolak</th>
                <th>Adjustment Dicatat</th>
                <th>Qty Diajukan</th>
                <th>Qty Adjustment</th>
                <th>Movement Tertaut</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ format_number_id((int) $summary['total_requests']) }}</td>
                <td>{{ format_number_id((int) $summary['pending_approval_count']) }}</td>
                <td>{{ format_number_id((int) $summary['approved_count']) }}</td>
                <td>{{ format_number_id((int) $summary['rejected_count']) }}</td>
                <td>{{ format_number_id((int) $summary['adjustment_recorded_count']) }}</td>
                <td>{{ format_quantity_id((float) $summary['total_quantity_requested']) }}</td>
                <td>{{ format_quantity_id((float) $summary['total_quantity_adjustment_recorded']) }}</td>
                <td>{{ format_number_id((int) $summary['movement_linked_count']) }}</td>
            </tr>
        </tbody>
    </table>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                @if ($scope['cross_branch'] ?? false)
                    <th>Cabang</th>
                @endif
                <th>Produk</th>
                <th>Batch</th>
                <th>Expired</th>
                <th>Lokasi</th>
                <th>Jenis</th>
                <th>Status</th>
                <th>Qty</th>
                <th>Action Log</th>
                <th>Movement</th>
                <th>Dibuat oleh</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $item)
                <tr>
                    <td>{{ format_datetime_id($item->submitted_at ?? $item->created_at) }}</td>
                    @if ($scope['cross_branch'] ?? false)
                        <td>{{ $item->branch?->name ?? '—' }}</td>
                    @endif
                    <td>{{ $item->product?->name ?? '—' }}</td>
                    <td>{{ $item->batch?->batch_number ?? '—' }}</td>
                    <td>{{ $item->batch?->expiry_date ? format_date_id($item->batch->expiry_date) : '—' }}</td>
                    <td>{{ $item->location?->name ?? '—' }}</td>
                    <td>{{ $item->requestTypeLabel() }}</td>
                    <td>{{ $item->statusLabel() }}</td>
                    <td>{{ format_quantity_id((float) $item->quantity_requested) }}</td>
                    <td>
                        @if ($item->actionLog)
                            {{ $item->actionLog->actionTypeLabel() }}
                            ({{ $item->actionLog->actor?->name ?? '—' }})
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if ($item->movement)
                            {{ $item->movement->movement_type }} OUT {{ format_quantity_id((float) $item->movement->quantity_out) }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $item->submittedBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ ($scope['cross_branch'] ?? false) ? 12 : 11 }}">Tidak ada data.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                Admin Warehouse<br><br><br>
                _______________________
            </td>
            <td>
                Supervisor / Owner<br><br><br>
                _______________________
            </td>
        </tr>
    </table>
</body>
</html>
