{{--
    Step 7 — Selesai.

    The controller refuses to render this page unless readiness actually
    passes, so "READY FOR CLINICAL USE" cannot appear over a tablet that is
    not. That is the one sentence here an operator will act on without
    re-reading the checklist.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="complete">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-ink">Langkah 7 — Selesai</h3>
            <x-ui.badge tone="success">READY FOR CLINICAL USE</x-ui.badge>
        </div>

        <x-ui.alert variant="success" class="mt-4">
            Perangkat <strong>{{ $device->device_name }}</strong> sudah siap dipakai klinis.
        </x-ui.alert>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-ink-muted">Nama Perangkat</dt><dd class="text-ink">{{ $device->device_name }}</dd></div>
            <div><dt class="text-ink-muted">Cabang</dt><dd class="text-ink">{{ $device->branch?->name ?? '—' }}</dd></div>
            <div>
                <dt class="text-ink-muted">Approval</dt>
                <dd><x-ui.badge tone="success">{{ $device->status }}</x-ui.badge></dd>
            </div>
            <div>
                <dt class="text-ink-muted">Kredensial dapat dipakai</dt>
                <dd class="text-ink">{{ $readinessReport['usable_credentials'] }}</dd>
            </div>
            <div>
                <dt class="text-ink-muted">Bukti login terakhir</dt>
                <dd class="text-ink">
                    {{ $readinessReport['proof']['last_proof_local']
                        ?? $readinessReport['proof']['last_proof_utc']
                        ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-ink-muted">Readiness</dt>
                <dd><x-ui.badge tone="success">{{ $readinessReport['verdict'] }}</x-ui.badge></dd>
            </div>
        </dl>
    </x-ui.card>

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Dokter yang Diotorisasi</h3>

        @if ($authorizations->isEmpty())
            <x-ui.empty-state class="mt-4" title="Belum ada dokter aktif" description="—" />
        @else
            <div class="mt-4 overflow-x-auto">
                <x-ui.table>
                    <thead><tr><th>Dokter</th><th>Status</th><th>Disetujui</th></tr></thead>
                    <tbody>
                        @foreach ($authorizations as $authorization)
                            <tr>
                                <td class="font-medium text-ink">{{ $authorization->doctor?->name ?? '—' }}</td>
                                <td><x-ui.badge tone="success">{{ $authorization->status }}</x-ui.badge></td>
                                <td class="text-sm text-ink-soft">
                                    {{ $authorization->approved_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif

        <div class="mt-5 flex flex-wrap gap-2">
            <x-ui.button variant="secondary" :href="route('settings.doctor-devices.show', $device)">
                Lihat Device
            </x-ui.button>
            @can('register', App\Modules\DoctorDevice\Models\DoctorDevice::class)
                <x-ui.button variant="primary" :href="route('settings.doctor-device-registration.create')">
                    Daftarkan Device Lain
                </x-ui.button>
            @endcan
            <x-ui.button variant="ghost" :href="route('settings.doctor-device-registration.index')">
                Kembali ke Pendaftaran Device Dokter
            </x-ui.button>
        </div>
    </x-ui.card>
</x-doctor-device-registration-shell>
