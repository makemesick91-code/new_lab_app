{{--
    DOCTOR-PWA-WEBAUTHN-1 — enrol THIS browser onto a clinic device.

    Opened on the tablet being enrolled, by an operator who may manage that
    device. The page carries no trust decision: it posts a signed credential to
    the server, which decides everything.
--}}
<x-settings-shell title="Kredensial Perangkat (WebAuthn)">
    @php
        $verdictTone = [
            'device_bound' => 'success',
            'backup_eligible' => 'danger',
            'unknown' => 'warning',
        ];
        $verdictLabel = [
            'device_bound' => 'Terikat perangkat',
            'backup_eligible' => 'Dapat disinkronkan',
            'unknown' => 'Tidak diketahui',
        ];
    @endphp

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">{{ $device->device_name }}</h2>
                <p class="text-sm text-ink-soft">{{ $device->branch?->name ?? '—' }}</p>
            </div>
            <x-ui.button variant="secondary" size="sm" href="{{ route('settings.doctor-devices.show', $device) }}">
                Kembali ke Perangkat
            </x-ui.button>
        </div>

        @if (session('success'))
            <x-ui.alert variant="success" class="mt-4">{{ session('success') }}</x-ui.alert>
        @endif

        @error('credential')
            <x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>
        @enderror

        @error('device')
            <x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>
        @enderror

        @if ($configurationFailure !== null)
            {{-- A ceremony started from an unusable configuration fails inside
                 the browser with no explanation, so say it here instead. --}}
            <x-ui.alert variant="danger" class="mt-4">
                Konfigurasi WebAuthn belum siap ({{ $configurationFailure }}). Pendaftaran tidak dapat dijalankan.
            </x-ui.alert>
        @else
            <x-ui.alert variant="info" class="mt-4">
                Buka halaman ini <strong>di tablet klinik yang akan didaftarkan</strong>. Kredensial akan dibuat oleh
                perangkat itu sendiri dan kunci privatnya tidak pernah meninggalkan perangkat.
                @if ($requiresDeviceBound)
                    Kredensial yang dapat disinkronkan ke perangkat lain akan <strong>ditolak</strong>.
                @endif
            </x-ui.alert>
        @endif

        @if ($device->status !== \App\Modules\DoctorDevice\Models\DoctorDevice::STATUS_ACTIVE)
            <x-ui.alert variant="warning" class="mt-4">
                Status perangkat saat ini <strong>{{ $device->status }}</strong>. Mendaftarkan kredensial
                <em>tidak</em> menyetujui perangkat — persetujuan tetap dilakukan melalui Approval Device Dokter.
            </x-ui.alert>
        @endif

        @if ($configurationFailure === null)
            <form method="POST" action="{{ route('settings.doctor-devices.webauthn.store', $device) }}" class="mt-5"
                  data-webauthn-register
                  data-options-url="{{ route('settings.doctor-devices.webauthn.options', $device) }}">
                @csrf
                <input type="hidden" name="credential[id]">
                <input type="hidden" name="credential[rawId]">
                <input type="hidden" name="credential[type]">
                <input type="hidden" name="credential[response][clientDataJSON]">
                <input type="hidden" name="credential[response][attestationObject]">

                <x-ui.button type="submit" variant="primary" data-webauthn-start>
                    Daftarkan Browser Ini
                </x-ui.button>

                <p class="mt-2 text-sm text-danger-700 hidden" data-webauthn-error></p>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card class="mt-6">
        <h3 class="text-base font-semibold text-ink">Kredensial Terdaftar</h3>

        @if ($credentials->isEmpty())
            <x-ui.empty-state class="mt-4" title="Belum ada kredensial"
                              description="Perangkat ini belum memiliki kredensial browser." />
        @else
            <div class="mt-4 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th>Status Ikatan</th>
                            <th>Verifikasi Pengguna</th>
                            <th>Didaftarkan</th>
                            <th>Terakhir Dipakai</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($credentials as $credential)
                            <tr>
                                <td>
                                    <x-ui.badge :tone="$verdictTone[$credential->device_bound_verdict] ?? 'neutral'">
                                        {{ $verdictLabel[$credential->device_bound_verdict] ?? $credential->device_bound_verdict }}
                                    </x-ui.badge>
                                </td>
                                <td>{{ $credential->user_verified ? 'Ya' : 'Tidak' }}</td>
                                <td>{{ $credential->registered_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>{{ $credential->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td>
                                    @if ($credential->isRevoked())
                                        <x-ui.badge tone="danger">Dicabut</x-ui.badge>
                                    @else
                                        <x-ui.badge tone="success">Aktif</x-ui.badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @unless ($credential->isRevoked())
                                        <form method="POST"
                                              action="{{ route('settings.doctor-devices.webauthn.revoke', [$device, $credential]) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf
                                            <input type="text" name="reason" required minlength="5" maxlength="500"
                                                   placeholder="Alasan pencabutan"
                                                   class="rounded-lg border-hairline text-sm focus:border-brand-500">
                                            <x-ui.button type="submit" variant="danger" size="sm">Cabut</x-ui.button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif
    </x-ui.card>

    @push('scripts')
        <script>
            document.querySelectorAll('form[data-webauthn-register]').forEach((form) => {
                const button = form.querySelector('[data-webauthn-start]');
                const error = form.querySelector('[data-webauthn-error]');

                form.addEventListener('submit', async (event) => {
                    // The credential fields are still empty on the first click,
                    // so the ceremony runs and the form is submitted again once
                    // the authenticator has answered.
                    if (form.dataset.webauthnReady === '1') {
                        return;
                    }

                    event.preventDefault();
                    error.classList.add('hidden');

                    if (!window.doctorDeviceWebAuthn?.isSupported()) {
                        error.textContent = 'Browser ini tidak mendukung WebAuthn.';
                        error.classList.remove('hidden');
                        return;
                    }

                    button.disabled = true;

                    try {
                        const credential = await window.doctorDeviceWebAuthn.register(form.dataset.optionsUrl);

                        form.querySelector('[name="credential[id]"]').value = credential.id;
                        form.querySelector('[name="credential[rawId]"]').value = credential.rawId;
                        form.querySelector('[name="credential[type]"]').value = credential.type;
                        form.querySelector('[name="credential[response][clientDataJSON]"]').value =
                            credential.response.clientDataJSON;
                        form.querySelector('[name="credential[response][attestationObject]"]').value =
                            credential.response.attestationObject;

                        form.dataset.webauthnReady = '1';
                        form.submit();
                    } catch (e) {
                        error.textContent = 'Pendaftaran dibatalkan atau gagal pada perangkat ini.';
                        error.classList.remove('hidden');
                        button.disabled = false;
                    }
                });
            });
        </script>
    @endpush
</x-settings-shell>
