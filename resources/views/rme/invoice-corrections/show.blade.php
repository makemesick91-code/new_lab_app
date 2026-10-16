@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
    $status = $correction->status;
    $isApplied = $status === RmeInvoiceCorrectionStatus::APPLIED;
@endphp
<x-settings-shell title="Detail Koreksi Tagihan">
    <div class="space-y-6">
        <x-ui.page-header :title="'Koreksi '.$correction->correction_number"
            :subtitle="RmeInvoiceCorrectionType::label($correction->correction_type)">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir · Koreksi Tagihan</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.invoice-corrections.index')">&larr; Daftar Koreksi</x-ui.button>
                @if ($correction->invoice && $correction->clinicVisit)
                    @can('view', $correction->invoice)
                        <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$correction->clinicVisit, $correction->invoice])">Lihat Tagihan</x-ui.button>
                    @endcan
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($status === RmeInvoiceCorrectionStatus::APPROVED)
            <x-ui.alert variant="info" data-correction-approved-not-applied>
                Disetujui, BELUM diterapkan — tagihan masih menampilkan nilai asli sampai koreksi diterapkan.
            </x-ui.alert>
        @elseif ($status === RmeInvoiceCorrectionStatus::SUBMITTED)
            <x-ui.alert variant="warning" data-correction-pending>
                Menunggu persetujuan akun lain — tagihan belum berubah.
            </x-ui.alert>
        @elseif ($status === RmeInvoiceCorrectionStatus::STALE)
            <x-ui.alert variant="warning">
                Kedaluwarsa ({{ $correction->stale_reason_code }}): tagihan berubah sejak koreksi diajukan, sehingga koreksi tidak diterapkan.
            </x-ui.alert>
        @endif

        <x-ui.card>
            <div class="flex items-start justify-between">
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $correction->invoice?->invoice_number }}</dd></div>
                    <div><dt class="text-ink-soft">Pasien</dt><dd class="text-navy">{{ $correction->patient?->name }} <span class="font-mono text-xs text-ink-muted">{{ $correction->patient?->medical_record_number }}</span></dd></div>
                    <div><dt class="text-ink-soft">Cabang (cabang invoice)</dt><dd class="text-navy">{{ $correction->branch?->name }}</dd></div>
                    <div><dt class="text-ink-soft">Diajukan oleh</dt><dd class="text-navy">{{ $correction->requester?->name }} · {{ OversightFormat::clinicalTime($correction->requested_at, 'd/m/Y H:i') }} WITA</dd></div>
                    <div><dt class="text-ink-soft">Diputuskan oleh</dt><dd class="text-navy">{{ $correction->decider?->name ?? '—' }} @if ($correction->decided_at) · {{ OversightFormat::clinicalTime($correction->decided_at, 'd/m/Y H:i') }} WITA @endif</dd></div>
                    <div><dt class="text-ink-soft">Diterapkan oleh</dt><dd class="text-navy">{{ $correction->applier?->name ?? '—' }} @if ($correction->applied_at) · {{ OversightFormat::clinicalTime($correction->applied_at, 'd/m/Y H:i') }} WITA @endif</dd></div>
                </dl>
                <x-ui.badge :tone="RmeInvoiceCorrectionStatus::tone($status)">{{ RmeInvoiceCorrectionStatus::label($status) }}</x-ui.badge>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <x-ui.card><p class="text-xs text-ink-soft">Total Asli</p><p class="text-lg font-semibold text-navy">Rp {{ number_format((float) $correction->original_grand_total, 0, ',', '.') }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">Total Usulan</p><p class="text-lg font-semibold text-navy">Rp {{ number_format((float) $correction->proposed_grand_total, 0, ',', '.') }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">Selisih Usulan</p><p class="text-lg font-semibold {{ (float) $correction->difference_amount < 0 ? 'text-success-700' : 'text-warning-700' }}">Rp {{ number_format((float) $correction->difference_amount, 0, ',', '.') }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">Total Diterapkan</p><p class="text-lg font-semibold text-navy" data-correction-applied-total>{{ $isApplied ? 'Rp '.number_format((float) $correction->proposed_grand_total, 0, ',', '.') : 'Belum diterapkan' }}</p></x-ui.card>
        </div>

        <x-ui.card title="Rincian Baris (asli → usulan)" padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">#</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Operasi</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Asli</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Subtotal Asli</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Usulan</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Subtotal Usulan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($correction->lines as $line)
                        <tr>
                            <td class="px-4 py-2 text-ink-soft">{{ $line->line_no }}</td>
                            <td class="px-4 py-2"><x-ui.badge tone="neutral">{{ $line->operationLabel() }}</x-ui.badge></td>
                            <td class="px-4 py-2 text-ink">
                                @if ($line->original_item_id)
                                    {{ $line->original_description }} · {{ $line->original_qty }} × Rp {{ number_format((float) $line->original_unit_price, 0, ',', '.') }}
                                    @if ((float) $line->original_discount > 0) − Rp {{ number_format((float) $line->original_discount, 0, ',', '.') }} @endif
                                    <span class="block text-xs text-ink-muted" data-line-original-attribution>Tindakan: {{ $line->originalTreatment?->name ?? '—' }} · Dokter: {{ $line->originalDoctor?->name ?? '—' }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $line->original_subtotal !== null ? 'Rp '.number_format((float) $line->original_subtotal, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-2 text-ink">
                                @if ($line->proposed_description !== null)
                                    {{ $line->proposed_description }} · {{ $line->proposed_qty }} × Rp {{ number_format((float) $line->proposed_unit_price, 0, ',', '.') }}
                                    @if ((float) $line->proposed_discount > 0) − Rp {{ number_format((float) $line->proposed_discount, 0, ',', '.') }} @endif
                                    <span class="block text-xs text-ink-muted" data-line-proposed-attribution>Tindakan: {{ $line->proposedTreatment?->name ?? '—' }} · Dokter: {{ $line->proposedDoctor?->name ?? '—' }}</span>
                                    @if ($line->treatmentChanged())
                                        <x-ui.badge tone="warning" data-line-treatment-changed>Tindakan berubah</x-ui.badge>
                                    @endif
                                @else
                                    (dihapus)
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $line->proposed_subtotal !== null ? 'Rp '.number_format((float) $line->proposed_subtotal, 0, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Alasan Koreksi">
            <p class="whitespace-pre-line text-sm text-ink">{{ $correction->reason }}</p>
            @if ($correction->decision_note)
                <p class="mt-3 text-sm text-ink-soft"><span class="font-medium">Catatan keputusan:</span> {{ $correction->decision_note }}</p>
            @endif
            @if ($correction->cancel_reason)
                <p class="mt-3 text-sm text-ink-soft"><span class="font-medium">Alasan pembatalan:</span> {{ $correction->cancel_reason }}</p>
            @endif
        </x-ui.card>

        @if ($capabilityEnabled && RmeInvoiceCorrectionStatus::isActive($status))
            <x-ui.card title="Tindakan">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    @if ($status === RmeInvoiceCorrectionStatus::SUBMITTED)
                        @can('approve', $correction)
                            <form method="POST" action="{{ route('rme.invoice-corrections.approve', $correction->id) }}" class="space-y-2" data-correction-approve>
                                @csrf
                                <x-ui.textarea name="note" label="Catatan persetujuan (opsional)" rows="2" />
                                <x-ui.button type="submit" variant="success">Setujui</x-ui.button>
                            </form>
                        @endcan
                        @can('reject', $correction)
                            <form method="POST" action="{{ route('rme.invoice-corrections.reject', $correction->id) }}" class="space-y-2" data-correction-reject>
                                @csrf
                                <x-ui.textarea name="note" label="Alasan penolakan (wajib)" rows="2" required />
                                <x-ui.button type="submit" variant="danger">Tolak</x-ui.button>
                            </form>
                        @endcan
                    @endif
                    @if ($status === RmeInvoiceCorrectionStatus::APPROVED)
                        @can('apply', $correction)
                            <form method="POST" action="{{ route('rme.invoice-corrections.apply', $correction->id) }}" class="space-y-2" data-correction-apply>
                                @csrf
                                <p class="text-xs text-ink-soft">Menerapkan mengubah item dan total tagihan. Dokumen asli dibekukan sebagai versi v0.</p>
                                <x-ui.button type="submit" variant="primary">Terapkan Koreksi</x-ui.button>
                            </form>
                        @endcan
                    @endif
                    @can('cancel', $correction)
                        <form method="POST" action="{{ route('rme.invoice-corrections.cancel', $correction->id) }}" class="space-y-2" data-correction-cancel>
                            @csrf
                            <x-ui.textarea name="note" label="Alasan pembatalan (wajib)" rows="2" required />
                            <x-ui.button type="submit" variant="secondary">Batalkan Pengajuan</x-ui.button>
                        </form>
                    @endcan
                </div>
            </x-ui.card>
        @endif

        <x-ui.card title="Riwayat Status">
            <ol class="space-y-2 text-sm">
                @foreach ($correction->events as $event)
                    <li class="flex flex-wrap gap-2">
                        <span class="text-ink-muted">{{ OversightFormat::clinicalTime($event->occurred_at, 'd/m/Y H:i') }} WITA</span>
                        <span class="text-navy">{{ $event->from_status ? RmeInvoiceCorrectionStatus::label($event->from_status).' → ' : '' }}{{ RmeInvoiceCorrectionStatus::label($event->to_status) }}</span>
                        <span class="text-ink-soft">oleh {{ $event->actor?->name ?? '—' }}</span>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>

        @if ($versions->isNotEmpty())
            <x-ui.card title="Versi Dokumen Tagihan">
                <ul class="space-y-1 text-sm">
                    @foreach ($versions as $version)
                        <li>
                            <a class="text-brand-700 hover:underline" href="{{ route('rme.invoice-corrections.document-versions.show', $version->id) }}">{{ $version->label() }}</a>
                            <span class="font-mono text-xs text-ink-muted">{{ $version->document_reference }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
