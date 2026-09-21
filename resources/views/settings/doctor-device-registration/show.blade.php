{{-- Ringkasan — the whole spine on one page, derived from real state. --}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="ringkasan">
    @php
        $wf = App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationWorkflowService::class;

        $tone = [
            $wf::STATUS_COMPLETE => 'success',
            $wf::STATUS_CURRENT => 'primary',
            $wf::STATUS_PENDING => 'neutral',
            $wf::STATUS_ACTION_REQUIRED => 'warning',
            $wf::STATUS_FAILED => 'danger',
        ];
    @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Ringkasan Pendaftaran</h3>

        @if ($overview['is_ready'])
            <x-ui.alert variant="success" class="mt-4">
                Perangkat ini sudah <strong>siap dipakai klinis</strong>.
            </x-ui.alert>
        @else
            <x-ui.alert variant="warning" class="mt-4">
                Perangkat ini <strong>belum siap dipakai klinis</strong>. Selesaikan langkah yang masih terbuka di bawah.
            </x-ui.alert>
        @endif

        <div class="mt-4 overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th>Langkah</th>
                        <th>Status</th>
                        <th>Keterangan</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($steps as $step)
                        <tr>
                            <td class="font-medium text-ink">{{ $step['number'] }}. {{ $step['label'] }}</td>
                            <td><x-ui.badge :tone="$tone[$step['status']] ?? 'neutral'">{{ $step['status'] }}</x-ui.badge></td>
                            <td class="text-sm text-ink-soft">
                                {{ $step['detail'] ?? '—' }}
                                @if ($step['waiting_for'])
                                    <span class="block text-xs text-warning-700">{{ $step['waiting_for'] }}</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <x-ui.button size="sm" variant="secondary"
                                             :href="route('settings.doctor-device-registration.' . $step['key'], $device)">
                                    Buka
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </div>
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Identitas Perangkat</h3>
        <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-ink-muted">Nama</dt><dd class="text-ink">{{ $device->device_name }}</dd></div>
            <div><dt class="text-ink-muted">Cabang</dt><dd class="text-ink">{{ $device->branch?->name ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Platform</dt><dd class="text-ink">{{ $device->platform ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Model</dt><dd class="text-ink">{{ $device->device_model ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Status Identitas</dt><dd class="text-ink">{{ $device->identity_status ?? '—' }}</dd></div>
            <div><dt class="text-ink-muted">Didaftarkan</dt><dd class="text-ink">{{ $device->created_at?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        </dl>
    </x-ui.card>
</x-doctor-device-registration-shell>
