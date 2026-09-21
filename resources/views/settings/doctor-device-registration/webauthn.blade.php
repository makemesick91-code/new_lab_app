{{--
    Step 2 — enrol THIS browser onto the tablet.

    MUST BE OPENED ON THE TABLET BEING REGISTERED. The ceremony runs in the
    browser and the private key never leaves the device; the server only ever
    receives a signed attestation and decides everything about it.

    Posts to the EXISTING WebAuthn endpoints — no second ceremony, no second
    challenge store, no second device-binding verdict. The hidden flag only
    asks them to come back here.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="webauthn">
    @php
        $verdictTone = ['device_bound' => 'success', 'backup_eligible' => 'danger', 'unknown' => 'warning'];
        $verdictLabel = [
            'device_bound' => 'Terikat perangkat',
            'backup_eligible' => 'Dapat disinkronkan',
            'unknown' => 'Tidak diketahui',
        ];
    @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Langkah 2 — Registrasi WebAuthn</h3>

        @error('credential')<x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>@enderror
        @error('device')<x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>@enderror

        @if (! $canEnrol)
            <x-ui.alert variant="warning" class="mt-4">
                <span>
                    <strong>Menunggu Super Admin.</strong>
                    Pendaftaran kredensial perangkat memerlukan wewenang pengelola perangkat
                    (<em>manage_doctor_devices</em>). Perangkat sudah terdaftar dan langkah ini dapat
                    dikerjakan oleh Super Admin pada tablet yang bersangkutan.
                </span>
            </x-ui.alert>
        @elseif ($configurationFailure !== null)
            {{-- A ceremony started from an unusable configuration fails inside the
                 browser with no explanation, so say it here instead. --}}
            <x-ui.alert variant="danger" class="mt-4">
                Konfigurasi WebAuthn belum siap ({{ $configurationFailure }}). Pendaftaran tidak dapat dijalankan.
            </x-ui.alert>
        @else
            <x-ui.alert variant="info" class="mt-4">
                Buka halaman ini <strong>di tablet klinik yang akan didaftarkan</strong>. Kredensial dibuat oleh
                perangkat itu sendiri dan kunci privatnya tidak pernah meninggalkan perangkat.
                @if ($requiresDeviceBound)
                    Kredensial yang dapat disinkronkan ke perangkat lain akan <strong>ditolak</strong>.
                @endif
            </x-ui.alert>

            <x-ui.alert variant="warning" class="mt-3">
                Mendaftarkan kredensial <strong>tidak</strong> menyetujui perangkat. Persetujuan pendaftaran
                tetap dilakukan pada Langkah 3.
            </x-ui.alert>

            <form method="POST" action="{{ route('settings.doctor-devices.webauthn.store', $device) }}" class="mt-5"
                  data-webauthn-register
                  data-options-url="{{ route('settings.doctor-devices.webauthn.options', $device) }}">
                @csrf
                <input type="hidden" name="registration_workflow" value="1">
                <input type="hidden" name="credential[id]">
                <input type="hidden" name="credential[rawId]">
                <input type="hidden" name="credential[type]">
                <input type="hidden" name="credential[response][clientDataJSON]">
                <input type="hidden" name="credential[response][attestationObject]">

                <x-ui.button type="submit" variant="primary" data-webauthn-start>
                    Daftarkan Perangkat Ini
                </x-ui.button>

                <p class="mt-2 hidden text-sm text-danger-700" data-webauthn-error></p>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card>
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
                                    @if ($canEnrol && ! $credential->isRevoked())
                                        <form method="POST"
                                              action="{{ route('settings.doctor-devices.webauthn.revoke', [$device, $credential]) }}"
                                              class="flex items-center justify-end gap-2">
                                            @csrf
                                            <input type="hidden" name="registration_workflow" value="1">
                                            <input type="text" name="reason" required minlength="5" maxlength="500"
                                                   placeholder="Alasan pencabutan"
                                                   class="rounded-lg border-hairline text-sm focus:border-brand-500">
                                            <x-ui.button type="submit" variant="danger" size="sm">Cabut</x-ui.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>
        @endif

        <div class="mt-5">
            <x-ui.button variant="secondary" :href="route('settings.doctor-device-registration.approval', $device)">
                Lanjut ke Approval Device
            </x-ui.button>
        </div>
    </x-ui.card>

    {{-- Scoped to the operator who can actually enrol: shipping the ceremony
         handler to a filer who has no form to drive would put the control's
         markers on a page that deliberately has no control. --}}
    @if ($canEnrol && $configurationFailure === null)
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
    @endif
</x-doctor-device-registration-shell>
