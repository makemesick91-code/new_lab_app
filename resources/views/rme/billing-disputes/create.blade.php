@php
    use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
@endphp
<x-settings-shell title="Laporkan Ketidaksesuaian Tagihan">
    <div class="space-y-6">
        <x-ui.page-header title="Laporkan Ketidaksesuaian Tagihan"
            subtitle="Untuk tagihan yang nominalnya diketahui atau diduga salah. Laporan tidak mengubah tagihan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Kasir</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali ke Tagihan</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Apa yang terjadi setelah laporan dikirim">
            <ul class="list-disc space-y-1 pl-5">
                <li>Tagihan <span class="font-mono">{{ $invoice->invoice_number }}</span> <strong>tidak diubah</strong> — total, item, dan status tetap seperti sekarang.</li>
                <li>Pembayaran tagihan ini <strong>ditahan</strong> di semua jalur kasir sampai laporan diselesaikan oleh penanggung jawab cabang.</li>
                <li>Kasus diteruskan ke penanggung jawab cabang. Koreksi nominal, bila memang perlu, tetap melalui prosedur koreksi yang berwenang.</li>
                <li><strong>Jangan menagih nominal yang diketahui salah, dan jangan menahan pasien.</strong> Pasien boleh pulang; tagihan tetap tercatat belum selesai.</li>
            </ul>
        </x-ui.alert>

        <x-ui.card title="Tagihan">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $invoice->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Total Tagihan</dt><dd class="font-semibold text-navy">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</dd></div>
                <div><dt class="text-ink-soft">Sudah Dibayar</dt><dd class="font-semibold text-navy">Rp {{ number_format($invoice->paidAmount(), 0, ',', '.') }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Laporan">
            <form method="POST" action="{{ route('rme.billing-disputes.store', [$visit, $invoice]) }}" class="space-y-4" data-billing-dispute-form>
                @csrf
                <x-ui.select name="reason_category" label="Jenis ketidaksesuaian" required>
                    <option value="">— Pilih —</option>
                    @foreach (RmeBillingDisputeReason::LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(old('reason_category') === $value)>{{ $label }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.textarea name="description" label="Keterangan (10–1000 karakter)" rows="4" required
                    help="Jelaskan apa yang tidak sesuai (mis. item atau harga mana). Jangan tulis NIK, diagnosis, atau data pribadi pasien." :value="old('description')" />
                @error('rme_invoice_id')
                    <p class="text-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" variant="danger">Kirim Laporan &amp; Tahan Pembayaran</x-ui.button>
                    <x-ui.button variant="neutral" :href="route('rme.cashier.show', [$visit, $invoice])">Batal</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
