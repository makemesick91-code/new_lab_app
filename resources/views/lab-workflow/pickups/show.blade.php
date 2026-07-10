<x-settings-shell :title="'Tugas Pickup — '.($task->labOrder?->order_number ?? '#'.$task->id)">
    <x-ui.page-header :title="'Tugas Pickup — '.($task->labOrder?->order_number ?? '#'.$task->id)"
        subtitle="Penjemputan model dari cabang ke laboratorium.">
        <x-slot:breadcrumb>Lab / Pickup / {{ $task->labOrder?->order_number ?? $task->id }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-pickup-tasks.index')" variant="secondary">Kembali</x-ui.button>
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
            <x-ui.card title="Informasi Pickup">
                <dl class="grid gap-3 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-ink-muted">Status Order</dt>
                        <dd class="mt-1">@include('lab-workflow.partials.v2-status-badge', ['status' => $task->labOrder?->status])</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Status Tugas</dt>
                        <dd class="mt-1 text-ink">{{ $task->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Cabang Pickup</dt>
                        <dd class="mt-1 text-ink">{{ $task->branch?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Alamat</dt>
                        <dd class="mt-1 text-ink">{{ $task->branch?->address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Kontak Cabang</dt>
                        <dd class="mt-1 text-ink">{{ $task->branch?->phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Kurir</dt>
                        <dd class="mt-1 text-ink">{{ $task->courier?->name ?? 'Belum diklaim' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Prioritas</dt>
                        <dd class="mt-1 text-ink">{{ $task->labOrder?->priority ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-muted">Tenggat Order</dt>
                        <dd class="mt-1 text-ink">{{ optional($task->labOrder?->due_date)->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
                @if ($task->pickup_notes)
                    <p class="mt-3 rounded-lg bg-navy-50 p-3 text-sm text-ink-soft">Catatan: {{ $task->pickup_notes }}</p>
                @endif
                @if ($task->discrepancy_note)
                    <x-ui.alert variant="warning" class="mt-3">Catatan ketidaksesuaian: {{ $task->discrepancy_note }}</x-ui.alert>
                @endif
            </x-ui.card>

            <x-ui.card title="Item / Model">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <th class="px-4 py-3 text-left">Layanan</th>
                            <th class="px-4 py-3 text-left">Gigi</th>
                            <th class="px-4 py-3 text-right">Jumlah</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($task->labOrder?->items ?? [] as $item)
                        <tr class="border-t border-hairline">
                            <td class="px-4 py-3 text-ink">{{ $item->labService?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-soft">{{ $item->tooth_number ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-ink-soft">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Foto Bukti">
                <div class="grid gap-3 md:grid-cols-3">
                    @foreach ($task->labOrder?->workflowEvidence ?? [] as $evidence)
                        <div>
                            <p class="mb-1 text-xs font-medium text-ink">{{ $evidence->typeLabel() }}</p>
                            <a href="{{ route('lab-workflow-evidence.show', $evidence) }}" target="_blank" rel="noopener">
                                <img src="{{ route('lab-workflow-evidence.show', $evidence) }}" alt="{{ $evidence->typeLabel() }}"
                                    class="h-28 w-full rounded-lg border border-hairline object-cover" />
                            </a>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @can('accept', $task)
                @if ($task->status === 'PENDING')
                    <x-ui.card title="Terima Tugas">
                        <form method="POST" action="{{ route('lab-pickup-tasks.accept', $task) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Terima Tugas Pickup</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            @can('progress', $task)
                @if ($task->status === 'ACCEPTED')
                    <x-ui.card title="Konfirmasi Pickup">
                        <p class="mb-2 text-sm text-ink-soft">Foto model saat pickup wajib diunggah.</p>
                        <form method="POST" action="{{ route('lab-pickup-tasks.picked-up', $task) }}" enctype="multipart/form-data" class="space-y-2">
                            @csrf
                            <input type="file" name="pickup_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required
                                class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                            <x-ui.textarea name="notes" label="Catatan (opsional, mis. ketidaksesuaian)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Model Diambil</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                @if ($task->status === 'PICKED_UP')
                    <x-ui.card title="Mulai Perjalanan ke Lab">
                        <form method="POST" action="{{ route('lab-pickup-tasks.start-transit', $task) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Mulai Perjalanan</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            @can('receive', $task)
                @if ($task->status === 'IN_TRANSIT')
                    <x-ui.card title="Konfirmasi Penerimaan Lab">
                        <p class="mb-2 text-sm text-ink-soft">Hanya petugas lab yang dapat mengkonfirmasi penerimaan model.</p>
                        <form method="POST" action="{{ route('lab-pickup-tasks.receive', $task) }}" class="space-y-2">
                            @csrf
                            <x-ui.textarea name="discrepancy_note" label="Catatan ketidaksesuaian (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full" variant="success">Model Diterima di Lab</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            <x-ui.card title="Kronologi">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-muted">Dibuat</dt><dd class="text-ink">{{ optional($task->created_at)->format('d M H:i') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Diterima Kurir</dt><dd class="text-ink">{{ optional($task->accepted_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Model Diambil</dt><dd class="text-ink">{{ optional($task->picked_up_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Berangkat ke Lab</dt><dd class="text-ink">{{ optional($task->in_transit_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Diterima Lab</dt><dd class="text-ink">{{ optional($task->received_at)->format('d M H:i') ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>
        </div>
    </div>
</x-settings-shell>
