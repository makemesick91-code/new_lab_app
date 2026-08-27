@php
    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — the odontogram is NOT part of
    // the RME print composition. "Cetak RME" prints the medical record; the
    // odontogram has its own standalone print (rme.odontograms.print). The
    // odontogram model, table, workflow, UI and standalone print are unchanged —
    // only this print composition drops the section. The odontogram status/condition
    // label maps went with it: they were only ever read by the removed block.
    //
    // FIX-RME-PRINT-REMOVE-PATIENT-VISIT-DUPLICATE-1 — the "Data Pasien &
    // Kunjungan" card is NOT part of the RME print composition either. It
    // restated as typed metadata what the clinician had already written by hand
    // on the RM sheet reproduced immediately below it, so the first printed page
    // duplicated the second. The patient, the visit and every one of those
    // fields are unchanged in the database and still shown on the visit detail
    // screen; only this composition drops the card, and it drops it for BOTH
    // consumers (browser print and the dompdf export) because both include this
    // one partial. Document IDENTIFICATION is deliberately kept and lives in the
    // wrapper instead: each wrapper's <title> carries the patient name and visit
    // number and its footer carries the visit number, which is what makes a
    // printed sheet attributable without reinstating the duplicate.
    $medicalRecord = $visit->medicalRecord;
    // Sprint 60 — render every RM page (Page 1 legacy read-through + Page 2+).
    // STORAGE-PUBLIC-CLINICAL-EVIDENCE-1: pass true so handwriting is embedded
    // inline. This partial is shared by the browser print view and the dompdf
    // PDF export, and dompdf cannot present a session cookie to the authorised
    // image route, so a linked image would export blank.
    $rmPages = $medicalRecord ? $medicalRecord->orderedHandwritingPages(true)->filter(fn ($p) => $p['has_content']) : collect();
@endphp

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
            @if ($rmPages->isNotEmpty())
                @foreach ($rmPages as $rmPage)
                    <p class="handwriting-saved-at">
                        Halaman {{ $rmPage['page_number'] }} &mdash; Tersimpan pada {{ $rmPage['saved_at']?->format('d/m/Y H:i') }}
                    </p>
                    <img src="{{ $rmPage['preview_url'] }}"
                         alt="RME Tulisan Tangan Halaman {{ $rmPage['page_number'] }}">
                @endforeach
            @else
                <p class="field-empty">Belum ada handwriting RM.</p>
            @endif
        </div>
    @else
        <div class="not-available">Rekam medis belum tersedia.</div>
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
