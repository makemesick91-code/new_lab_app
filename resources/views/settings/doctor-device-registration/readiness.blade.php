{{--
    Step 6 — the readiness checklist.

    Every gate is listed with its own verdict, and a failing gate links to the
    step that can fix it. Nothing is computed in this file: the gates come from
    DoctorDeviceRegistrationReadinessService, which consumes the same policies
    and the same REASON_* vocabulary as fleet readiness.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="readiness">
    @php
        $rd = App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationReadinessService::class;

        $tone = [
            $rd::GATE_PASS => 'success',
            $rd::GATE_FAIL => 'danger',
            $rd::GATE_UNVERIFIED => 'warning',
        ];
        $stepLabel = collect($steps)->keyBy('key');
    @endphp

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-ink">Langkah 6 — Verifikasi Readiness</h3>
            <x-ui.badge :tone="$readinessReport['verdict'] === $rd::READY ? 'success' : 'danger'">
                {{ $readinessReport['verdict'] }}
            </x-ui.badge>
        </div>

        @error('readiness')<x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>@enderror

        @if ($readinessReport['verdict'] === $rd::READY)
            <x-ui.alert variant="success" class="mt-4">
                Semua gate lulus. Perangkat ini siap dipakai klinis.
            </x-ui.alert>
        @else
            <x-ui.alert variant="danger" class="mt-4">
                {{ count($readinessReport['failed_gates']) }} gate belum lulus. Perangkat
                <strong>belum</strong> siap dipakai klinis.
            </x-ui.alert>
        @endif

        <x-ui.alert variant="info" class="mt-3">
            <span>
                <strong>UNVERIFIED bukan lulus.</strong> Status itu berarti gate tersebut belum dapat diukur —
                bukan bahwa keadaannya baik.
            </span>
        </x-ui.alert>

        <div class="mt-4 overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr><th>Gate</th><th>Status</th><th>Alasan</th><th>Perbaiki di</th></tr>
                </thead>
                <tbody>
                    @foreach ($readinessReport['gates'] as $gate)
                        <tr @class(['bg-danger-50' => $gate['status'] === $rd::GATE_FAIL])>
                            <td>
                                <span class="font-medium text-ink">{{ $gate['label'] }}</span>
                                <span class="block font-mono text-xs text-ink-muted">{{ $gate['key'] }}</span>
                            </td>
                            <td><x-ui.badge :tone="$tone[$gate['status']] ?? 'neutral'">{{ $gate['status'] }}</x-ui.badge></td>
                            <td class="font-mono text-xs text-ink-soft">{{ $gate['reason'] ?? '—' }}</td>
                            <td>
                                @if ($gate['status'] !== $rd::GATE_PASS && $gate['step'] !== 'readiness')
                                    <a class="text-sm font-semibold text-brand-700 hover:underline"
                                       href="{{ route('settings.doctor-device-registration.' . $gate['step'], $device) }}">
                                        {{ $stepLabel[$gate['step']]['label'] ?? $gate['step'] }}
                                    </a>
                                @else
                                    <span class="text-sm text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </div>

        <div class="mt-5">
            @if ($readinessReport['verdict'] === $rd::READY)
                <x-ui.button variant="primary" :href="route('settings.doctor-device-registration.complete', $device)">
                    Lanjut ke Selesai
                </x-ui.button>
            @else
                <x-ui.button variant="secondary" :href="route('settings.doctor-device-registration.show', $device)">
                    Kembali ke Ringkasan
                </x-ui.button>
            @endif
        </div>
    </x-ui.card>
</x-doctor-device-registration-shell>
