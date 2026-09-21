{{--
    Step 1 — the tablet's own record.

    Metadata editing posts to the EXISTING update route, which is
    `manage_doctor_devices`: renaming or re-branching a trusted tablet is a
    change to a device already in service, so the filing authority sees the
    values read-only and is told whose turn it is.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="device">
    @php $canEdit = auth()->user()->can('update', $device); @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Langkah 1 — Data Device</h3>
        <p class="mt-1 text-sm text-ink-soft">
            Perangkat sudah tercatat di registri. Data di bawah dapat diperbarui oleh pengelola perangkat.
        </p>

        @if ($canEdit)
            <form method="POST" action="{{ route('settings.doctor-devices.update', $device) }}" class="mt-5">
                @csrf
                @method('PUT')
                <input type="hidden" name="registration_workflow" value="1">

                @include('settings.doctor-devices._form', ['device' => $device, 'branches' => $branches])

                <div class="mt-5 flex gap-2">
                    <x-ui.button type="submit" variant="primary">Simpan Perubahan</x-ui.button>
                    <x-ui.button variant="secondary"
                                 :href="route('settings.doctor-device-registration.webauthn', $device)">
                        Lanjut ke Registrasi WebAuthn
                    </x-ui.button>
                </div>
            </form>
        @else
            <x-ui.alert variant="info" class="mt-4">
                Data perangkat hanya dapat diubah oleh pengelola perangkat (Super Admin).
                Anda tetap dapat melanjutkan ke langkah berikutnya.
            </x-ui.alert>

            <dl class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                <div><dt class="text-ink-muted">Nama</dt><dd class="text-ink">{{ $device->device_name }}</dd></div>
                <div><dt class="text-ink-muted">Cabang</dt><dd class="text-ink">{{ $device->branch?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Platform</dt><dd class="text-ink">{{ $device->platform ?? '—' }}</dd></div>
                <div><dt class="text-ink-muted">Model</dt><dd class="text-ink">{{ $device->device_model ?? '—' }}</dd></div>
            </dl>

            <div class="mt-5">
                <x-ui.button variant="primary"
                             :href="route('settings.doctor-device-registration.webauthn', $device)">
                    Lanjut ke Registrasi WebAuthn
                </x-ui.button>
            </div>
        @endif
    </x-ui.card>
</x-doctor-device-registration-shell>
