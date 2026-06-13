@php
    $medicalRecord = $visit->medicalRecord;
    $odontogram = $visit->odontogram;
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
    $savedHandwriting = $medicalRecord?->latestHandwriting();
@endphp

{{-- Patient & Visit Info --}}
<div class="section-block">
    <div class="section-title">Data Pasien &amp; Kunjungan</div>
    <div class="info-grid">
        <div class="info-row">
            <dt>Nama Pasien</dt>
            <dd>{{ $visit->patient?->name ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>No. Rekam Medis</dt>
            <dd>{{ $visit->patient?->medical_record_number ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>No. Kunjungan</dt>
            <dd>{{ $visit->visit_number ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Tanggal Kunjungan</dt>
            <dd>{{ $visit->visit_date?->format('d/m/Y') ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Cabang</dt>
            <dd>{{ $visit->branch?->name ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Klinik</dt>
            <dd>{{ $visit->clinic?->name ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Antrian</dt>
            <dd>#{{ $visit->queue_number ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Dokter</dt>
            <dd>{{ $visit->doctor?->name ?? '—' }}</dd>
        </div>
        @if ($visit->initialTreatment)
            <div class="info-row" style="grid-column: 1 / -1;">
                <dt>Tindakan Awal</dt>
                <dd>{{ $visit->initialTreatment->name }}</dd>
            </div>
        @endif
        <div class="info-row" style="grid-column: 1 / -1;">
            <dt>Keluhan Utama</dt>
            <dd>{{ $visit->chief_complaint ?? '—' }}</dd>
        </div>
    </div>
</div>

{{-- Medical Record --}}
<div class="section-block">
    <div class="section-title">Rekam Medis
        @if ($medicalRecord)
            &nbsp;
            @if ($medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL)
                <span class="status-badge status-final">Final</span>
            @else
                <span class="status-badge status-draft">Draft</span>
            @endif
        @endif
    </div>
    @if ($medicalRecord)
        @if ($medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL && $medicalRecord->finalized_at)
            <p class="meta-line">Difinalisasi pada {{ $medicalRecord->finalized_at->format('d/m/Y H:i') }}</p>
        @endif

        <div class="handwriting-preview">
            <div class="section-title" style="margin-bottom: 8px; border-bottom: none; padding-bottom: 0;">RME Tulisan Tangan</div>
            @if ($savedHandwriting && $savedHandwriting->previewUrl())
                <p class="handwriting-saved-at">
                    Tersimpan pada {{ $savedHandwriting->saved_at?->format('d/m/Y H:i') }}
                </p>
                <img src="{{ $savedHandwriting->previewUrl() }}"
                     alt="RME Tulisan Tangan">
            @else
                <p class="field-empty">Belum ada handwriting RM.</p>
            @endif
        </div>
    @else
        <div class="not-available">Rekam medis belum tersedia.</div>
    @endif
</div>

{{-- Odontogram Summary --}}
<div class="section-block">
    <div class="section-title">Odontogram
        @if ($odontogram)
            &nbsp;
            @if ($odontogram->isFinalized())
                <span class="status-badge status-finalized">Final</span>
            @else
                <span class="status-badge status-draft">Draft</span>
            @endif
        @endif
    </div>
    @if ($odontogram)
        <div class="odonto-summary">
            @if ($odontogram->additional_conditions)
                <div class="field-item">
                    <dt style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">Kondisi Tambahan</dt>
                    <div class="odonto-notes">{{ $odontogram->additional_conditions }}</div>
                </div>
            @endif

            @if ($odontogram->summary_notes)
                <div class="field-item">
                    <dt style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">Catatan Odontogram</dt>
                    <div class="odonto-notes">{{ $odontogram->summary_notes }}</div>
                </div>
            @endif

            @php
                $teethData = $odontogram->tooth_map_payload['teeth'] ?? [];
                $markedTeeth = array_filter($teethData, fn ($td) =>
                    ! empty($td['status']) || ! empty($td['conditions']) || (isset($td['note']) && $td['note'] !== '')
                );
                ksort($markedTeeth);
            @endphp

            @if (count($markedTeeth) > 0)
                <table class="odonto-table" style="margin-top: {{ $odontogram->summary_notes ? '10px' : '0' }}">
                    <thead>
                        <tr>
                            <th style="width:55px">Gigi</th>
                            <th style="width:120px">Status</th>
                            <th>Kondisi Tambahan</th>
                            <th>Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($markedTeeth as $toothNum => $td)
                            <tr>
                                <td><strong>{{ $toothNum }}</strong></td>
                                <td>{{ $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '—') }}</td>
                                <td>
                                    @if (! empty($td['conditions']) && is_array($td['conditions']))
                                        {{ implode(', ', array_map(fn ($c) => $conditionLabels[$c] ?? $c, $td['conditions'])) }}
                                    @else
                                        <span style="color:#9ca3af">—</span>
                                    @endif
                                </td>
                                <td>{{ (isset($td['note']) && $td['note'] !== '') ? $td['note'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="margin-top:6px;color:#9ca3af;font-style:italic;font-size:11px;">Belum ada gigi yang ditandai.</p>
            @endif
        </div>
    @else
        <div class="not-available">Odontogram belum tersedia.</div>
    @endif
</div>

{{-- Invoice & Payment --}}
@if ($paidInvoice)
    <div class="section-block">
        <div class="section-title">Tagihan &amp; Pembayaran RME</div>
        <div class="info-grid">
            <div class="info-row">
                <dt>No. Invoice</dt>
                <dd>{{ $paidInvoice->invoice_number }}</dd>
            </div>
            <div class="info-row">
                <dt>Status</dt>
                <dd><span class="status-badge status-final">LUNAS</span></dd>
            </div>
            @if ($payment)
                <div class="info-row">
                    <dt>No. Kwitansi</dt>
                    <dd>{{ $payment->payment_number ?? '—' }}</dd>
                </div>
                <div class="info-row">
                    <dt>Tanggal Bayar</dt>
                    <dd>{{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div class="info-row">
                    <dt>Metode Pembayaran</dt>
                    <dd>{{ $payment->paymentMethod?->name ?? 'Tunai' }}</dd>
                </div>
                <div class="info-row">
                    <dt>Jumlah Dibayar</dt>
                    <dd>Rp {{ number_format($payment->amount ?? $paidInvoice->grand_total, 0, ',', '.') }}</dd>
                </div>
            @endif
            <div class="info-row" style="grid-column: 1 / -1;">
                <dt>Total Tagihan</dt>
                <dd>Rp {{ number_format($paidInvoice->grand_total, 0, ',', '.') }}</dd>
            </div>
        </div>

        @if ($paidInvoice->items->isNotEmpty())
            <table class="odonto-table" style="margin-top: 10px;">
                <thead>
                    <tr>
                        <th>Tindakan</th>
                        <th style="width:50px;text-align:right">Qty</th>
                        <th style="width:110px;text-align:right">Harga</th>
                        <th style="width:110px;text-align:right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paidInvoice->items as $item)
                        <tr>
                            <td>{{ $item->description }}</td>
                            <td style="text-align:right">{{ $item->qty }}</td>
                            <td style="text-align:right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td style="text-align:right">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif

{{-- Lab Workflow --}}
@if (($paidInvoice && $paidInvoice->isPaid()) || ($labCaseCandidates ?? collect())->isNotEmpty())
    <div class="section-block print-lab-workflow">
        <div class="section-title">Status Pekerjaan Lab RME</div>
        @if (($labCaseCandidates ?? collect())->isNotEmpty())
            <table class="odonto-table">
                <thead>
                    <tr>
                        <th>Tindakan</th>
                        <th style="width:130px">Status</th>
                        <th style="width:110px;text-align:right">Estimasi</th>
                        <th style="width:140px">Referensi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($labCaseCandidates as $candidate)
                        <tr>
                            <td>{{ $candidate->source_description ?? $candidate->treatment?->name ?? '—' }}</td>
                            <td>{{ $candidate->statusLabel() }}</td>
                            <td style="text-align:right">{{ $candidate->displayEstimatedPrice() }}</td>
                            <td>
                                Kandidat #{{ $candidate->id }}
                                @if ($candidate->isConverted() && $candidate->convertedLabOrder)
                                    — {{ $candidate->convertedLabOrder->order_number }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="not-available">
                Belum ada kandidat pekerjaan lab untuk tagihan ini.
            </div>
        @endif
    </div>
@endif
