{{--
    Step 3 — admit the filed tablet into service.

    THE TRUST DECISION, and deliberately the second party's. Supervisor RME
    files a tablet; only `manage_doctor_devices` approves it. Posts to the
    EXISTING approve-registration route, whose policy enforces exactly that.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="approval">
    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Langkah 3 — Approval Device</h3>

        <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
            <div><dt class="text-ink-muted">Perangkat</dt><dd class="text-ink">{{ $device->device_name }}</dd></div>
            <div><dt class="text-ink-muted">Cabang</dt><dd class="text-ink">{{ $device->branch?->name ?? '—' }}</dd></div>
            <div>
                <dt class="text-ink-muted">Status Perangkat</dt>
                <dd><x-ui.badge :tone="$device->isActive() ? 'success' : ($device->isPendingApproval() ? 'warning' : 'danger')">{{ $device->status }}</x-ui.badge></dd>
            </div>
            <div>
                <dt class="text-ink-muted">Kredensial WebAuthn</dt>
                <dd class="text-ink">{{ $readinessReport['usable_credentials'] }} dapat dipakai</dd>
            </div>
        </dl>

        @if ($device->isRevoked())
            <x-ui.alert variant="danger" class="mt-4">
                Perangkat ini sudah <strong>dicabut</strong>. Pencabutan bersifat final — daftarkan perangkat baru
                jika tablet ini akan dipakai lagi.
            </x-ui.alert>
        @elseif ($device->isDisabled())
            <x-ui.alert variant="warning" class="mt-4">
                Perangkat ini <strong>dinonaktifkan</strong>. Aktifkan kembali melalui halaman Device Dokter.
            </x-ui.alert>
        @elseif ($device->isPendingApproval())
            @if ($canApprove)
                <x-ui.alert variant="warning" class="mt-4">
                    Perangkat menunggu persetujuan. Menyetujui berarti tablet ini boleh dipakai login oleh dokter
                    yang diotorisasi — pastikan perangkat fisiknya benar.
                </x-ui.alert>

                <form method="POST" action="{{ route('settings.doctor-devices.approve-registration', $device) }}"
                      class="mt-5">
                    @csrf
                    <input type="hidden" name="registration_workflow" value="1">
                    <x-ui.button type="submit" variant="primary">Setujui Pendaftaran Perangkat</x-ui.button>
                </form>
            @else
                <x-ui.alert variant="warning" class="mt-4">
                    <span>
                        <strong>Menunggu Super Admin.</strong>
                        Perangkat sudah terdaftar dan menunggu persetujuan dari pengelola perangkat
                        (<em>manage_doctor_devices</em>). Sampai disetujui, perangkat ini ditolak oleh gerbang login.
                    </span>
                </x-ui.alert>
            @endif
        @else
            <x-ui.alert variant="success" class="mt-4">
                Pendaftaran perangkat sudah disetujui.
            </x-ui.alert>
        @endif

        <div class="mt-5">
            <x-ui.button variant="secondary" :href="route('settings.doctor-device-registration.doctors', $device)">
                Lanjut ke Authorization Dokter
            </x-ui.button>
        </div>
    </x-ui.card>
</x-doctor-device-registration-shell>
