<x-settings-shell title="Follow-up Piutang RME">
    @php
        $statusLabels = [
            'NEW' => 'Baru',
            'CONTACTED' => 'Sudah Dihubungi',
            'PROMISED' => 'Janji Bayar',
            'FOLLOW_UP_LATER' => 'Follow-up Lagi',
            'ESCALATED' => 'Eskalasi',
            'CLOSED' => 'Selesai',
        ];
        $channelLabels = [
            'WHATSAPP' => 'WhatsApp',
            'PHONE' => 'Telepon',
            'IN_PERSON' => 'Langsung / Tatap Muka',
            'OTHER' => 'Lainnya',
        ];
        $remainingAmount = $invoice->remainingAmount();
    @endphp

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-purple-700">Piutang RME</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Tambah Follow-up Piutang</h2>
                <p class="mt-1 text-sm text-gray-500">Catat tindakan follow-up / pengingat untuk tagihan RME yang belum lunas. Ini hanya pencatatan — tidak mengubah status pembayaran.</p>
            </div>
            <x-ui.button variant="neutral" :href="route('rme.cashier.receivables')">&larr; Kembali ke Piutang RME</x-ui.button>
        </div>

        <x-ui.card title="Informasi Tagihan">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">No. Invoice</dt>
                    <dd class="font-mono font-medium text-gray-900">{{ $invoice->invoice_number }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Status</dt>
                    <dd class="font-medium text-amber-700">{{ $invoice->status }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Pasien</dt>
                    <dd class="font-medium text-gray-900">{{ $invoice->patient?->name ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Cabang</dt>
                    <dd class="text-gray-900">{{ $invoice->branch?->code }} — {{ $invoice->branch?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Sisa Tagihan</dt>
                    <dd class="text-base font-bold text-amber-700">Rp {{ number_format($remainingAmount, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Form Follow-up">
            <form method="POST" action="{{ route('rme.cashier.receivables.follow-ups.store', $invoice) }}" class="space-y-5">
                @csrf

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status <span class="text-red-500">*</span></label>
                    <select name="status" id="status"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('status') border-red-500 @enderror">
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" {{ old('status', 'NEW') === $status ? 'selected' : '' }}>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="channel" class="block text-sm font-medium text-gray-700 mb-1">Channel</label>
                    <select name="channel" id="channel"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('channel') border-red-500 @enderror">
                        <option value="">— Tidak ditentukan —</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel }}" {{ old('channel') === $channel ? 'selected' : '' }}>{{ $channelLabels[$channel] ?? $channel }}</option>
                        @endforeach
                    </select>
                    @error('channel')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="contacted_at" class="block text-sm font-medium text-gray-700 mb-1">Tanggal Kontak</label>
                    <input type="datetime-local" name="contacted_at" id="contacted_at"
                        value="{{ old('contacted_at') }}"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('contacted_at') border-red-500 @enderror">
                    @error('contacted_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="next_follow_up_date" class="block text-sm font-medium text-gray-700 mb-1">Reminder Berikutnya</label>
                    <input type="date" name="next_follow_up_date" id="next_follow_up_date"
                        value="{{ old('next_follow_up_date') }}"
                        class="block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('next_follow_up_date') border-red-500 @enderror">
                    @error('next_follow_up_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="note" class="block text-sm font-medium text-gray-700 mb-1">Catatan</label>
                    <textarea name="note" id="note" rows="3"
                        class="block w-full max-w-xl rounded-md border-gray-300 shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500 @error('note') border-red-500 @enderror">{{ old('note') }}</textarea>
                    @error('note')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <x-ui.button type="submit" variant="primary">Simpan Follow-up</x-ui.button>
                    <x-ui.button variant="neutral" :href="route('rme.cashier.receivables')">Batal</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card title="Riwayat Follow-up">
            @if ($followUps->isEmpty())
                <p class="text-sm text-gray-500">Belum ada riwayat follow-up untuk tagihan ini.</p>
            @else
                <ul class="divide-y divide-gray-100">
                    @foreach ($followUps as $item)
                        <li class="py-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-gray-900">{{ $statusLabels[$item->status] ?? $item->status }}</p>
                                <p class="text-xs text-gray-500">{{ $item->created_at?->format('d/m/Y H:i') }}</p>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $channelLabels[$item->channel] ?? ($item->channel ?? 'Tanpa channel') }}
                                @if ($item->next_follow_up_date) · Reminder: {{ $item->next_follow_up_date->format('d/m/Y') }} @endif
                                @if ($item->user) · oleh {{ $item->user->name }} @endif
                            </p>
                            @if ($item->note)
                                <p class="mt-1 text-sm text-gray-700">{{ $item->note }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
