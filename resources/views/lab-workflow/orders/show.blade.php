@php
    use App\Modules\LabOrder\Workflow\LabWorkflowState as S;
    $status = (string) $order->status;
    $stepLabels = [
        'STEP_1_BLOCKOUT_DUPLICATE' => 'Step 1 — Block Out & Duplikat',
        'STEP_2_TEETH_SETUP' => 'Step 2 — Penyusunan Gigi',
        'STEP_3_PROCESSING' => 'Step 3 — Penanaman, Boiling, Injek',
        'STEP_4_FITTING_POLISH' => 'Step 4 — Fitting & Polish',
    ];
    $activeAssignment = $order->assignments->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])->sortByDesc('id')->first();
@endphp
<x-settings-shell :title="'Order V2 '.$order->order_number">
    <x-ui.page-header :title="'Order V2 '.$order->order_number" subtitle="Hub pengelolaan analisa, produksi, QC, dan lab eksternal.">
        <x-slot:breadcrumb>Lab / Pipeline V2 / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-v2-orders.index')" variant="secondary">Kembali</x-ui.button>
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
            <x-ui.card title="Ringkasan">
                <dl class="grid gap-3 text-sm md:grid-cols-3">
                    <div><dt class="text-ink-muted">Status</dt>
                        <dd class="mt-1">@include('lab-workflow.partials.v2-status-badge', ['status' => $status])</dd></div>
                    <div><dt class="text-ink-muted">Cabang Asal</dt><dd class="mt-1 text-ink">{{ $order->branch?->name ?? '—' }}</dd></div>
                    <div><dt class="text-ink-muted">Tenggat</dt><dd class="mt-1 text-ink">{{ optional($order->due_date)->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-ink-muted">Pasien</dt><dd class="mt-1 text-ink">{{ $order->patient?->name ?? '—' }}</dd></div>
                    <div><dt class="text-ink-muted">Prioritas</dt><dd class="mt-1 text-ink">{{ $order->priority }}</dd></div>
                    <div><dt class="text-ink-muted">Teknisi</dt><dd class="mt-1 text-ink">{{ $activeAssignment?->technician?->name ?? '—' }}</dd></div>
                </dl>
            </x-ui.card>

            {{-- Production checklist --}}
            @if ($order->productionSteps->isNotEmpty())
                <x-ui.card title="Step Produksi Internal">
                    <ol class="space-y-2">
                        @foreach ($order->productionSteps as $step)
                            <li class="flex items-center justify-between rounded-lg border border-hairline px-3 py-2 text-sm">
                                <span class="text-ink">{{ $stepLabels[$step->step_name] ?? $step->step_name }}</span>
                                <span class="flex items-center gap-2">
                                    @if ($step->notes)<span class="text-xs text-ink-muted">{{ $step->notes }}</span>@endif
                                    <x-ui.badge :tone="match($step->status) { 'COMPLETED' => 'success', 'IN_PROGRESS' => 'info', default => 'neutral' }">
                                        {{ $step->status }}
                                    </x-ui.badge>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.card>
            @endif

            {{-- Analyses history --}}
            @if ($order->modelAnalyses->isNotEmpty())
                <x-ui.card title="Riwayat Analisa">
                    <ul class="space-y-2 text-sm">
                        @foreach ($order->modelAnalyses->sortByDesc('id') as $analysis)
                            <li class="rounded-lg border border-hairline p-3">
                                <p class="font-medium text-ink">
                                    {{ $analysis->decision === 'INTERNAL' ? 'Dikerjakan Internal' : 'Dikirim ke Lab Eksternal' }}
                                    @if ($analysis->externalLab) — {{ $analysis->externalLab->name }} @endif
                                </p>
                                <p class="text-ink-soft">{{ $analysis->reason }}</p>
                                <p class="mt-1 text-xs text-ink-muted">{{ $analysis->analyst?->name }} · {{ optional($analysis->analyzed_at)->format('d M Y H:i') }}</p>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            {{-- External dispatches history --}}
            @if ($order->externalDispatches->isNotEmpty())
                <x-ui.card title="Riwayat Lab Eksternal">
                    <ul class="space-y-2 text-sm">
                        @foreach ($order->externalDispatches->sortByDesc('id') as $dispatch)
                            <li class="rounded-lg border border-hairline p-3">
                                <div class="flex items-center justify-between">
                                    <p class="font-medium text-ink">{{ $dispatch->externalLab?->name }}</p>
                                    <x-ui.badge :tone="match($dispatch->status) { 'REVIEWED' => $dispatch->review_result === 'ACCEPTED' ? 'success' : 'danger', 'CANCELLED' => 'danger', default => 'info' }">
                                        {{ $dispatch->status }}{{ $dispatch->review_result ? ' — '.$dispatch->review_result : '' }}
                                    </x-ui.badge>
                                </div>
                                <p class="mt-1 text-xs text-ink-muted">
                                    Kirim: {{ optional($dispatch->sent_at)->format('d M H:i') ?? '—' }} ·
                                    Kembali: {{ optional($dispatch->returned_at)->format('d M H:i') ?? '—' }} ·
                                    Ref: {{ $dispatch->reference_number ?? '—' }}
                                </p>
                                @if ($dispatch->review_notes)<p class="mt-1 text-xs text-ink-soft">{{ $dispatch->review_notes }}</p>@endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card title="Riwayat Status">
                <ol class="max-h-96 space-y-3 overflow-y-auto">
                    @foreach ($order->statusLogs->sortByDesc('changed_at')->take(50) as $log)
                        <li class="flex items-start gap-3 text-sm">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                            <div>
                                <p class="text-ink">{{ $log->old_status ? $log->old_status.' → ' : '' }}{{ $log->new_status }}</p>
                                <p class="text-ink-muted">{{ optional($log->changed_at)->format('d M Y H:i') }}@if ($log->notes) — {{ $log->notes }}@endif</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            {{-- Register model --}}
            @if ($status === S::RECEIVED_AT_LAB)
                @can('manage_lab_orders')
                    <x-ui.card title="Input / Validasi Model">
                        <form method="POST" action="{{ route('lab-v2-orders.register', $order) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Daftarkan Model untuk Analisa</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcan
            @endif

            {{-- Analysis decision --}}
            @if ($status === S::MODEL_ANALYSIS_PENDING)
                @can('manage_lab_orders')
                    <x-ui.card title="Analisa Model">
                        <form method="POST" action="{{ route('lab-v2-orders.analyze', $order) }}" class="space-y-2"
                            x-data="{ decision: 'INTERNAL' }">
                            @csrf
                            <x-ui.select name="decision" label="Keputusan" x-model="decision" required>
                                <option value="INTERNAL">Dikerjakan Internal</option>
                                <option value="EXTERNAL">Dikirim ke Lab Lain</option>
                            </x-ui.select>
                            <div x-show="decision === 'EXTERNAL'" x-cloak>
                                <x-ui.select name="external_lab_id" label="Lab Eksternal Tujuan">
                                    <option value="">Pilih lab eksternal</option>
                                    @foreach ($externalLabs as $lab)
                                        <option value="{{ $lab->id }}">{{ $lab->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                            <x-ui.textarea name="reason" label="Alasan Keputusan" rows="2" required></x-ui.textarea>
                            <x-ui.textarea name="notes" label="Catatan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Simpan Keputusan Analisa</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcan
            @endif

            {{-- Technician assignment --}}
            @if ($status === S::INTERNAL_APPROVED || $status === S::TECHNICIAN_ASSIGNMENT_PENDING)
                @canany(['assign_technicians', 'manage_production'])
                    <x-ui.card title="Assign Teknisi">
                        <form method="POST" action="{{ route('lab-v2-orders.assign-technician', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.select name="technician_id" label="Teknisi" required>
                                <option value="">Pilih teknisi</option>
                                @foreach ($technicians as $technician)
                                    <option value="{{ $technician->id }}">{{ $technician->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.textarea name="notes" label="Catatan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Tugaskan Teknisi</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
            @endif

            {{-- Production step actions --}}
            @php
                $nextStart = match ($status) {
                    S::TECHNICIAN_ASSIGNED => S::STEP_1_BLOCKOUT_DUPLICATE,
                    S::STEP_1_COMPLETED => S::STEP_2_TEETH_SETUP,
                    S::STEP_2_COMPLETED => S::STEP_3_PROCESSING,
                    S::STEP_3_COMPLETED => S::STEP_4_FITTING_POLISH,
                    default => null,
                };
                $completing = array_key_exists($status, S::V2_PRODUCTION_STEPS) ? $status : null;
            @endphp
            @if ($nextStart)
                @canany(['start_production_work', 'manage_production'])
                    <x-ui.card title="Mulai Step Berikutnya">
                        <form method="POST" action="{{ route('lab-v2-orders.steps.start', $order) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="step" value="{{ $nextStart }}" />
                            <p class="text-sm text-ink-soft">{{ $stepLabels[$nextStart] }}</p>
                            <x-ui.textarea name="notes" label="Catatan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Mulai {{ $stepLabels[$nextStart] }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
            @endif
            @if ($completing)
                @canany(['complete_production_work', 'manage_production'])
                    <x-ui.card title="Selesaikan Step Berjalan">
                        <form method="POST" action="{{ route('lab-v2-orders.steps.complete', $order) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="step" value="{{ $completing }}" />
                            <p class="text-sm text-ink-soft">{{ $stepLabels[$completing] }}</p>
                            <x-ui.textarea name="notes" label="Catatan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full" variant="success">Selesaikan Step</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
            @endif
            @if ($status === S::STEP_4_COMPLETED)
                @canany(['send_to_qc', 'manage_production'])
                    <x-ui.card title="Kirim ke Quality Control">
                        <form method="POST" action="{{ route('lab-v2-orders.send-to-qc', $order) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Kirim ke QC</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
            @endif

            {{-- QC decision --}}
            @if ($status === S::QC_PENDING)
                @canany(['pass_qc', 'manage_quality_control'])
                    <x-ui.card title="QC — Lulus">
                        <form method="POST" action="{{ route('lab-v2-orders.qc-pass', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.textarea name="notes" label="Catatan QC (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full" variant="success">QC Lulus — Model Selesai</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
                @canany(['reject_qc', 'manage_quality_control'])
                    <x-ui.card title="QC — Tidak Lulus">
                        <form method="POST" action="{{ route('lab-v2-orders.qc-fail', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.textarea name="reason" label="Alasan Gagal (wajib)" rows="2" required></x-ui.textarea>
                            <x-ui.select name="target_step" label="Target Rework" required>
                                @foreach ($reworkTargets as $target)
                                    <option value="{{ $target }}" @selected($target === S::DEFAULT_REWORK_TARGET)>{{ $stepLabels[$target] ?? $target }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button type="submit" class="w-full" variant="danger">QC Gagal — Rework</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcanany
            @endif

            {{-- External lab actions --}}
            @can('manage_lab_orders')
                @if ($status === S::EXTERNAL_LAB_REQUIRED)
                    <x-ui.card title="Siapkan Kirim ke Lab Eksternal">
                        <form method="POST" action="{{ route('lab-v2-orders.external-dispatch', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.select name="external_lab_id" label="Lab Eksternal (default dari analisa)">
                                <option value="">Gunakan tujuan dari analisa</option>
                                @foreach ($externalLabs as $lab)
                                    <option value="{{ $lab->id }}">{{ $lab->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.input type="date" name="expected_return_date" label="Estimasi Selesai" />
                            <x-ui.textarea name="reason" label="Alasan / catatan pengiriman" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Siapkan Pengiriman</x-ui.button>
                        </form>
                    </x-ui.card>
                @elseif ($status === S::EXTERNAL_LAB_PREPARATION)
                    <x-ui.card title="Tandai Terkirim">
                        <form method="POST" action="{{ route('lab-v2-orders.external-sent', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.input name="shipping_method" label="Kurir / Metode Kirim" />
                            <x-ui.input name="reference_number" label="No. Referensi / Resi" />
                            <x-ui.input type="date" name="expected_return_date" label="Estimasi Selesai" />
                            <x-ui.input type="number" name="cost" label="Biaya (opsional)" min="0" step="0.01" />
                            <x-ui.button type="submit" class="w-full">Model Terkirim</x-ui.button>
                        </form>
                    </x-ui.card>
                @elseif ($status === S::EXTERNAL_LAB_SENT)
                    <x-ui.card title="Status Lab Eksternal">
                        <form method="POST" action="{{ route('lab-v2-orders.external-in-progress', $order) }}">
                            @csrf
                            <x-ui.button type="submit" class="w-full">Sedang Dikerjakan Lab Eksternal</x-ui.button>
                        </form>
                    </x-ui.card>
                @elseif ($status === S::EXTERNAL_LAB_IN_PROGRESS)
                    <x-ui.card title="Model Kembali">
                        <form method="POST" action="{{ route('lab-v2-orders.external-returned', $order) }}" class="space-y-2">
                            @csrf
                            <x-ui.textarea name="notes" label="Catatan penerimaan (opsional)" rows="2"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Model Kembali — Masuk Review</x-ui.button>
                        </form>
                    </x-ui.card>
                @elseif ($status === S::EXTERNAL_LAB_RESULT_REVIEW)
                    <x-ui.card title="Review Hasil Lab Eksternal">
                        <form method="POST" action="{{ route('lab-v2-orders.external-review', $order) }}" class="space-y-2"
                            x-data="{ result: 'ACCEPTED' }">
                            @csrf
                            <x-ui.select name="result" label="Hasil Review" x-model="result" required>
                                <option value="ACCEPTED">Diterima — Model Selesai</option>
                                <option value="REJECTED">Ditolak — Kirim Ulang</option>
                            </x-ui.select>
                            <x-ui.textarea name="notes" label="Catatan Review" rows="2"
                                x-bind:required="result === 'REJECTED'"></x-ui.textarea>
                            <x-ui.button type="submit" class="w-full">Simpan Review</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif
            @endcan

            {{-- Evidence --}}
            @if ($order->workflowEvidence->isNotEmpty())
                <x-ui.card title="Foto Bukti">
                    <div class="grid gap-2">
                        @foreach ($order->workflowEvidence as $evidence)
                            <a href="{{ route('lab-workflow-evidence.show', $evidence) }}" target="_blank" rel="noopener"
                                class="flex items-center justify-between rounded-lg border border-hairline px-3 py-2 text-sm text-brand-700">
                                <span>{{ $evidence->typeLabel() }}</span>
                                <span class="text-xs text-ink-muted">{{ optional($evidence->captured_at)->format('d M H:i') }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-settings-shell>
