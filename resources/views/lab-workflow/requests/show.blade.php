<x-settings-shell :title="'Permintaan '.$order->order_number">
    <x-ui.page-header :title="'Permintaan '.$order->order_number" subtitle="Detail permintaan lab cabang (Workflow V2).">
        <x-slot:breadcrumb>Lab / Permintaan Cabang / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-workflow-requests.index')" variant="secondary">Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif
    @if ($errors->any())
        <x-ui.alert variant="danger" class="mb-4">
            <ul class="list-disc pl-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <x-ui.card title="Ringkasan Order">
                <dl class="grid gap-3 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-ink-muted">Status</dt>
                        <dd class="mt-1">@include('lab-workflow.partials.v2-status-badge', ['status' => $order->status])</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Cabang</dt>
                        <dd class="mt-1 text-ink">{{ $order->branch?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Pasien</dt>
                        <dd class="mt-1 text-ink">{{ $order->patient?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Dokter</dt>
                        <dd class="mt-1 text-ink">{{ $order->doctor?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Tanggal Order</dt>
                        <dd class="mt-1 text-ink">{{ optional($order->order_date)->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Tenggat</dt>
                        <dd class="mt-1 text-ink">{{ optional($order->due_date)->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
                @if ($order->notes)
                    <p class="mt-3 rounded-lg bg-navy-50 p-3 text-sm text-ink-soft">{{ $order->notes }}</p>
                @endif
            </x-ui.card>

            <x-ui.card title="Item Pekerjaan">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="px-4 py-3 text-left">Layanan</th>
                            <th class="px-4 py-3 text-left">Gigi</th>
                            <th class="px-4 py-3 text-right">Jumlah</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($order->items as $item)
                        <tr class="border-t border-hairline">
                            <td class="px-4 py-3 text-ink">{{ $item->labService?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item->tooth_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-ink-soft">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Riwayat Status">
                <ol class="space-y-3">
                    @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                        <li class="flex items-start gap-3 text-sm">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                            <div>
                                <p class="text-ink">
                                    {{ $log->old_status ? $log->old_status.' → ' : '' }}{{ $log->new_status }}
                                </p>
                                <p class="text-ink-muted">
                                    {{ optional($log->changed_at)->format('d M Y H:i') }}
                                    @if ($log->notes) — {{ $log->notes }} @endif
                                </p>
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-ink-muted">Belum ada riwayat.</li>
                    @endforelse
                </ol>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card title="Foto Bukti">
                @php
                    $spk = $order->workflowEvidence->firstWhere('type', 'SPK_PHOTO');
                    $model = $order->workflowEvidence->firstWhere('type', 'MODEL_PHOTO_BRANCH');
                @endphp
                <div class="space-y-3">
                    @foreach ([['Foto SPK', $spk], ['Foto Model', $model]] as [$label, $evidence])
                        <div>
                            <p class="mb-1 text-sm font-medium text-ink">{{ $label }}</p>
                            @if ($evidence)
                                <a href="{{ route('lab-workflow-evidence.show', $evidence) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('lab-workflow-evidence.show', $evidence) }}" alt="{{ $label }}"
                                        class="h-36 w-full rounded-lg border border-hairline object-cover" />
                                </a>
                                <p class="mt-1 text-xs text-ink-muted">Diunggah {{ optional($evidence->captured_at)->format('d M Y H:i') }}</p>
                            @else
                                <p class="rounded-lg border border-dashed border-hairline p-3 text-sm text-ink-muted">Belum diunggah.</p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($order->status === 'DRAFT')
                    <form method="POST" action="{{ route('lab-workflow-requests.evidence.store', $order) }}" enctype="multipart/form-data" class="mt-4 space-y-2 border-t border-hairline pt-3">
                        @csrf
                        <x-ui.select name="type" label="Unggah Ulang Foto">
                            <option value="SPK_PHOTO">Foto SPK</option>
                            <option value="MODEL_PHOTO_BRANCH">Foto Model</option>
                        </x-ui.select>
                        <input type="file" name="file" accept="image/jpeg,image/png,image/webp" capture="environment" required
                            class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                        <x-ui.button type="submit" size="sm" variant="secondary">Unggah</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            @if ($order->status === 'DRAFT')
                <x-ui.card title="Kirim Permintaan Pickup">
                    <p class="mb-3 text-sm text-ink-soft">Pastikan foto SPK dan foto model sudah benar. Setelah dikirim, kurir akan menerima tugas pickup.</p>
                    <form method="POST" action="{{ route('lab-workflow-requests.submit-pickup', $order) }}">
                        @csrf
                        <x-ui.button type="submit" class="w-full" :disabled="! $spk || ! $model">Kirim Permintaan Pickup</x-ui.button>
                    </form>
                    @if (! $spk || ! $model)
                        <p class="mt-2 text-xs text-warning-700">Foto SPK dan foto model wajib lengkap sebelum pickup.</p>
                    @endif
                </x-ui.card>
            @endif

            @if ($order->pickupTask)
                <x-ui.card title="Tugas Pickup">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-ink-muted">Status Tugas</dt><dd class="text-ink">{{ $order->pickupTask->status }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-muted">Kurir</dt><dd class="text-ink">{{ $order->pickupTask->courier?->name ?? 'Belum ada' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-muted">Diambil</dt><dd class="text-ink">{{ optional($order->pickupTask->picked_up_at)->format('d M H:i') ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-muted">Diterima Lab</dt><dd class="text-ink">{{ optional($order->pickupTask->received_at)->format('d M H:i') ?? '—' }}</dd></div>
                    </dl>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-settings-shell>
