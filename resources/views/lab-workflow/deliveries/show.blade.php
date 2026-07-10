@php
    $order = $task->labOrder;
@endphp
<x-settings-shell :title="'Pengiriman — '.($order?->order_number ?? '#'.$task->id)">
    <x-ui.page-header :title="'Pengiriman — '.($order?->order_number ?? '#'.$task->id)"
        subtitle="Pengantaran model dari laboratorium ke cabang dengan bukti wajib.">
        <x-slot:breadcrumb>Lab / Pengiriman V2 / {{ $order?->order_number ?? $task->id }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-delivery-tasks.index')" variant="secondary">Kembali</x-ui.button>
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
            <x-ui.card title="Informasi Pengiriman">
                <dl class="grid gap-3 text-sm md:grid-cols-2">
                    <div><dt class="text-ink-muted">Status Order</dt>
                        <dd class="mt-1">@include('lab-workflow.partials.v2-status-badge', ['status' => $order?->status])</dd></div>
                    <div><dt class="text-ink-muted">Status Tugas</dt><dd class="mt-1 text-ink">{{ $task->status }}</dd></div>
                    <div><dt class="text-ink-muted">Cabang Tujuan</dt><dd class="mt-1 text-ink">{{ $task->branch?->name ?? '—' }}</dd></div>
                    <div><dt class="text-ink-muted">Alamat</dt><dd class="mt-1 text-ink">{{ $task->branch?->address ?? '—' }}</dd></div>
                    <div><dt class="text-ink-muted">Kurir</dt><dd class="mt-1 text-ink">{{ $task->courier?->name ?? 'Belum diklaim' }}</dd></div>
                    <div><dt class="text-ink-muted">Penerima</dt>
                        <dd class="mt-1 text-ink">{{ $task->recipient_name ?? '—' }}{{ $task->recipient_role ? ' ('.$task->recipient_role.')' : '' }}</dd></div>
                </dl>
                @if ($task->delivery_notes)
                    <p class="mt-3 rounded-lg bg-navy-50 p-3 text-sm text-ink-soft">Catatan: {{ $task->delivery_notes }}</p>
                @endif
            </x-ui.card>

            <x-ui.card title="Bukti Serah Terima">
                <div class="grid gap-3 md:grid-cols-2">
                    @php
                        $proofTypes = [
                            'PRE_DELIVERY_HANDOVER_PHOTO' => 'Foto Serah Terima Lab → Kurir',
                            'COURIER_SIGNATURE' => 'Tanda Tangan Kurir',
                            'RECIPIENT_SIGNATURE' => 'Tanda Tangan Penerima',
                            'DELIVERY_LOCATION_PHOTO' => 'Foto Bukti Serah Terima',
                        ];
                    @endphp
                    @foreach ($proofTypes as $type => $label)
                        @php $evidence = $order?->workflowEvidence->firstWhere('type', $type); @endphp
                        <div>
                            <p class="mb-1 text-xs font-medium text-ink">{{ $label }}</p>
                            @if ($evidence)
                                <a href="{{ route('lab-workflow-evidence.show', $evidence) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('lab-workflow-evidence.show', $evidence) }}" alt="{{ $label }}"
                                        class="h-28 w-full rounded-lg border border-hairline bg-white object-contain" />
                                </a>
                            @else
                                <p class="rounded-lg border border-dashed border-hairline p-3 text-sm text-ink-muted">Belum ada.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card title="Kronologi">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-muted">Dibuat</dt><dd class="text-ink">{{ optional($task->created_at)->format('d M H:i') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Diterima Kurir</dt><dd class="text-ink">{{ optional($task->accepted_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Bukti Pra-Pengantaran Lengkap</dt><dd class="text-ink">{{ optional($task->ready_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Berangkat</dt><dd class="text-ink">{{ optional($task->in_transit_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Tiba di Cabang</dt><dd class="text-ink">{{ optional($task->arrived_at)->format('d M H:i') ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-muted">Terkirim</dt><dd class="text-ink">{{ optional($task->delivered_at)->format('d M H:i') ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            @can('accept', $task)
                @if ($task->status === 'PENDING')
                    <x-ui.card title="Terima Tugas Pengiriman">
                        <form method="POST" action="{{ route('lab-delivery-tasks.accept', $task) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Terima Tugas</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            @can('progress', $task)
                @if ($task->status === 'ACCEPTED')
                    <x-ui.card title="Serah Terima Lab → Kurir (Wajib)">
                        <p class="mb-2 text-sm text-warning-700">Foto serah terima DAN tanda tangan kurir wajib sebelum berangkat.</p>
                        <form method="POST" action="{{ route('lab-delivery-tasks.handover', $task) }}" enctype="multipart/form-data"
                            class="space-y-3"
                            x-data="signaturePad('courier_signature')">
                            @csrf
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink">Foto Serah Terima Model</label>
                                <input type="file" name="handover_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required
                                    class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink">Tanda Tangan Kurir</label>
                                <canvas x-ref="canvas" class="h-36 w-full touch-none rounded-lg border border-hairline bg-white"
                                    @pointerdown="start($event)" @pointermove="draw($event)" @pointerup="stop()" @pointerleave="stop()"></canvas>
                                <input type="hidden" name="courier_signature" x-ref="payload" />
                                <button type="button" class="mt-1 text-xs text-brand-700" @click="clearPad()">Bersihkan</button>
                            </div>
                            <x-ui.button type="submit" class="w-full" @click="capture()">Simpan Bukti & Siap Berangkat</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                @if ($task->status === 'READY_FOR_TRANSIT')
                    <x-ui.card title="Mulai Perjalanan ke Cabang">
                        <form method="POST" action="{{ route('lab-delivery-tasks.start-transit', $task) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Mulai Perjalanan</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                @if ($task->status === 'IN_TRANSIT')
                    <x-ui.card title="Tiba di Cabang">
                        <form method="POST" action="{{ route('lab-delivery-tasks.arrived', $task) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Saya Sudah Tiba</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            @can('complete', $task)
                @if ($task->status === 'ARRIVED')
                    <x-ui.card title="Serah Terima ke Penerima (Wajib)">
                        <p class="mb-2 text-sm text-warning-700">Nama, tanda tangan penerima, dan foto bukti wajib untuk menyelesaikan pengiriman.</p>
                        <form method="POST" action="{{ route('lab-delivery-tasks.complete', $task) }}" enctype="multipart/form-data"
                            class="space-y-3"
                            x-data="signaturePad('recipient_signature')">
                            @csrf
                            <x-ui.input name="recipient_name" label="Nama Penerima" required />
                            <x-ui.input name="recipient_role" label="Jabatan/Role (opsional)" />
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink">Tanda Tangan Penerima</label>
                                <canvas x-ref="canvas" class="h-36 w-full touch-none rounded-lg border border-hairline bg-white"
                                    @pointerdown="start($event)" @pointermove="draw($event)" @pointerup="stop()" @pointerleave="stop()"></canvas>
                                <input type="hidden" name="recipient_signature" x-ref="payload" />
                                <button type="button" class="mt-1 text-xs text-brand-700" @click="clearPad()">Bersihkan</button>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-ink">Foto Lokasi / Bukti Serah Terima</label>
                                <input type="file" name="location_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required
                                    class="block w-full rounded-lg border border-hairline text-sm text-ink-soft file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-brand-700" />
                            </div>
                            <x-ui.textarea name="notes" label="Catatan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full" variant="success" @click="capture()">Selesaikan Pengiriman</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan
        </div>
    </div>

    <script>
        // Minimal Alpine signature pad: draws on canvas, serializes PNG data URL
        // into the hidden input on submit. Server-side validation remains the
        // authority (regex + PNG magic bytes + size in the service layer).
        function signaturePad(fieldName) {
            return {
                drawing: false,
                dirty: false,
                ctx: null,
                init() {
                    const canvas = this.$refs.canvas;
                    canvas.width = canvas.offsetWidth;
                    canvas.height = canvas.offsetHeight;
                    this.ctx = canvas.getContext('2d');
                    this.ctx.fillStyle = '#ffffff';
                    this.ctx.fillRect(0, 0, canvas.width, canvas.height);
                    this.ctx.strokeStyle = '#1e293b';
                    this.ctx.lineWidth = 2;
                    this.ctx.lineCap = 'round';
                },
                pos(e) {
                    const rect = this.$refs.canvas.getBoundingClientRect();
                    return { x: e.clientX - rect.left, y: e.clientY - rect.top };
                },
                start(e) { this.drawing = true; this.dirty = true; const p = this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(p.x, p.y); },
                draw(e) { if (!this.drawing) return; const p = this.pos(e); this.ctx.lineTo(p.x, p.y); this.ctx.stroke(); },
                stop() { this.drawing = false; },
                clearPad() { this.dirty = false; this.init(); this.$refs.payload.value = ''; },
                capture() { if (this.dirty) { this.$refs.payload.value = this.$refs.canvas.toDataURL('image/png'); } },
            };
        }
    </script>
</x-settings-shell>
