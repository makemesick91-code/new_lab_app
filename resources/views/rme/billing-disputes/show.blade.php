@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
    $invoice = $dispute->invoice;
@endphp
<x-settings-shell title="Laporan Ketidaksesuaian Tagihan">
    <div class="space-y-6">
        <x-ui.page-header :title="'Laporan '.$dispute->dispute_number"
            subtitle="Laporan ketidaksesuaian tagihan. Laporan ini tidak mengubah tagihan; ia menahan pembayaran sampai diselesaikan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.billing-disputes.index')">&larr; Daftar Laporan</x-ui.button>
                @if ($canViewInvoice)
                    <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice->id])">Lihat Tagihan</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert variant="warning">{{ session('error') }}</x-ui.alert>
        @endif
        @if ($errors->any())
            <x-ui.alert variant="danger" data-billing-dispute-errors>
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        @if ($dispute->isActive())
            <x-ui.alert variant="danger" title="Pembayaran tagihan ditahan" data-billing-dispute-hold-notice>
                Tagihan ini sedang dalam peninjauan karena terdapat ketidaksesuaian nominal. Pembayaran sementara tidak dapat
                diproses di semua jalur kasir. Silakan hubungi penanggung jawab cabang. Jangan menagih nominal yang diketahui
                salah, jangan mengubah tagihan sendiri, dan jangan menahan pasien.
            </x-ui.alert>
            @if ($reviewerCount === 0)
                <x-ui.alert variant="warning" data-billing-dispute-unrouted>
                    Belum ada penanggung jawab peninjau yang aktif untuk cabang ini. Laporan tetap tercatat dan pembayaran tetap
                    ditahan; hubungi manajemen klinik.
                </x-ui.alert>
            @endif
        @endif

        <x-ui.card title="Ringkasan">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">Status</dt><dd><x-ui.badge :tone="RmeBillingDisputeStatus::tone($dispute->status)">{{ RmeBillingDisputeStatus::label($dispute->status) }}</x-ui.badge></dd></div>
                <div><dt class="text-ink-soft">Cabang</dt><dd class="text-navy">{{ $dispute->branch?->code }} — {{ $dispute->branch?->name }}</dd></div>
                <div><dt class="text-ink-soft">Jenis ketidaksesuaian</dt><dd class="text-navy">{{ RmeBillingDisputeReason::label($dispute->reason_category) }}</dd></div>
                <div><dt class="text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $invoice?->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Dilaporkan oleh</dt><dd class="text-navy">{{ $dispute->reporter?->name }}</dd></div>
                <div><dt class="text-ink-soft">Waktu laporan</dt><dd class="text-navy">{{ OversightFormat::clinicalTime($dispute->reported_at, 'd/m/Y H:i') }} WITA</dd></div>
                <div><dt class="text-ink-soft">Status tagihan saat dilaporkan</dt><dd class="text-navy">{{ $dispute->invoice_status_at_report }}</dd></div>
                <div><dt class="text-ink-soft">Total saat dilaporkan</dt><dd class="text-navy">Rp {{ number_format((float) $dispute->invoice_grand_total_at_report, 0, ',', '.') }}</dd></div>
                <div><dt class="text-ink-soft">Dibayar saat dilaporkan</dt><dd class="text-navy">Rp {{ number_format((float) $dispute->invoice_paid_total_at_report, 0, ',', '.') }}</dd></div>
                @if ($invoice)
                    <div><dt class="text-ink-soft">Status tagihan sekarang</dt><dd class="text-navy">{{ $invoice->status }}</dd></div>
                    <div><dt class="text-ink-soft">Total sekarang</dt><dd class="text-navy">Rp {{ number_format((float) $invoice->grand_total, 0, ',', '.') }}</dd></div>
                    <div><dt class="text-ink-soft">Versi tagihan (saat lapor → sekarang)</dt><dd class="text-navy">v{{ (int) $dispute->invoice_correction_version_at_report }} → v{{ (int) $invoice->correction_version }}</dd></div>
                @endif
            </dl>
            <div class="mt-4 text-sm">
                <p class="text-ink-soft">Keterangan pelapor</p>
                <p class="mt-1 whitespace-pre-line text-navy">{{ $dispute->description }}</p>
            </div>
        </x-ui.card>

        @if ($dispute->status === RmeBillingDisputeStatus::RESOLVED)
            <x-ui.card title="Penyelesaian" data-billing-dispute-resolution>
                <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-ink-soft">Hasil</dt><dd class="text-navy">{{ RmeBillingDisputeOutcome::label($dispute->resolution_outcome) }}</dd></div>
                    <div><dt class="text-ink-soft">Diselesaikan oleh</dt><dd class="text-navy">{{ $dispute->resolver?->name }}</dd></div>
                    <div><dt class="text-ink-soft">Waktu</dt><dd class="text-navy">{{ OversightFormat::clinicalTime($dispute->resolved_at, 'd/m/Y H:i') }} WITA</dd></div>
                    <div><dt class="text-ink-soft">Referensi</dt><dd class="font-mono text-navy">{{ $dispute->resolution_reference ?? '-' }}</dd></div>
                </dl>
                <p class="mt-3 whitespace-pre-line text-sm text-navy">{{ $dispute->resolution_reason }}</p>
            </x-ui.card>
        @endif

        <x-ui.card title="Riwayat" data-billing-dispute-timeline>
            <ol class="space-y-3 text-sm">
                @foreach ($timeline as $event)
                    <li class="border-l-2 border-hairline pl-3">
                        <div class="font-medium text-navy">{{ $event->label() }}</div>
                        <div class="text-xs text-ink-soft">{{ $event->actor?->name }} · {{ OversightFormat::clinicalTime($event->occurred_at, 'd/m/Y H:i') }} WITA
                            @if ($event->from_status !== $event->to_status)
                                · {{ $event->from_status ? RmeBillingDisputeStatus::label($event->from_status).' → ' : '' }}{{ RmeBillingDisputeStatus::label($event->to_status) }}
                            @endif
                        </div>
                        @if ($event->note)
                            <p class="mt-1 whitespace-pre-line text-ink">{{ $event->note }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-ui.card>

        @if ($dispute->isActive() && ($canNote || $canReview))
            <x-ui.card title="Tindak Lanjut" data-billing-dispute-actions>
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @if ($canNote)
                        <form method="POST" action="{{ route('rme.billing-disputes.notes.store', $dispute->id) }}" class="space-y-2" data-billing-dispute-note>
                            @csrf
                            <x-ui.textarea name="note" label="Catatan tindak lanjut (10–1000 karakter)" rows="2" required
                                help="Mis. pasien sudah pulang dan akan dihubungi kembali. Jangan tulis NIK atau diagnosis." />
                            <x-ui.button type="submit" variant="secondary">Tambah Catatan</x-ui.button>
                        </form>
                    @endif

                    @if ($canReview)
                        <div class="space-y-4">
                            @if ($dispute->status === RmeBillingDisputeStatus::OPEN)
                                <form method="POST" action="{{ route('rme.billing-disputes.review', $dispute->id) }}" class="space-y-2" data-billing-dispute-review>
                                    @csrf
                                    <x-ui.textarea name="note" label="Catatan tinjauan (opsional)" rows="2" />
                                    <x-ui.button type="submit" variant="primary">Mulai Tinjau</x-ui.button>
                                </form>
                            @endif
                            @if (in_array($dispute->status, [RmeBillingDisputeStatus::OPEN, RmeBillingDisputeStatus::UNDER_REVIEW], true))
                                <form method="POST" action="{{ route('rme.billing-disputes.escalate', $dispute->id) }}" class="space-y-2" data-billing-dispute-escalate>
                                    @csrf
                                    <x-ui.textarea name="note" label="Alasan eskalasi ke Owner (wajib)" rows="2" required />
                                    <x-ui.button type="submit" variant="warning">Eskalasi ke Owner</x-ui.button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('rme.billing-disputes.resolve', $dispute->id) }}" class="space-y-2" data-billing-dispute-resolve>
                                @csrf
                                <x-ui.select name="outcome" label="Hasil penyelesaian" required>
                                    <option value="">— Pilih —</option>
                                    @foreach (RmeBillingDisputeOutcome::LABELS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.textarea name="resolution_reason" label="Alasan penyelesaian (wajib)" rows="2" required />
                                <x-ui.input name="resolution_reference" label="Referensi penyelesaian (wajib bila tagihan asli dinyatakan benar)"
                                    help="Mis. referensi persetujuan Owner. Untuk koreksi yang diterapkan, nomor koreksi diisi otomatis oleh sistem." />
                                <p class="text-xs text-ink-soft">
                                    "Koreksi diterapkan" hanya dapat dipilih bila koreksi tagihan sudah diterapkan setelah laporan ini dibuat.
                                    "Tagihan asli dinyatakan benar" tidak dapat dipilih oleh pelapor sendiri dan hanya berlaku bila tagihan belum berubah.
                                </p>
                                <x-ui.button type="submit" variant="success">Selesaikan Laporan</x-ui.button>
                            </form>
                        </div>
                    @endif
                </div>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
