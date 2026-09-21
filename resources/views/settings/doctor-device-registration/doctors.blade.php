{{--
    Step 4 — which doctors may use this tablet.

    ONE DEVICE, MANY DOCTORS. A shared tablet is intentional: the WebAuthn
    credential belongs to the DEVICE, so adding another doctor never requires
    re-enrolling it.

    This page FILES requests. It cannot approve them — the decision stays in
    Approval Device Dokter. Ineligible doctors are shown and disabled rather
    than hidden, because a missing row is indistinguishable from a broken list.
--}}
<x-doctor-device-registration-shell :device="$device" :steps="$steps" active-step="doctors">
    @php
        $auth = App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization::class;
        $rollout = App\Modules\DoctorDevice\Services\DoctorGlobalRolloutReadinessService::class;

        $statusTone = [
            $auth::STATUS_ACTIVE => 'success',
            $auth::STATUS_PENDING => 'warning',
            $auth::STATUS_REJECTED => 'danger',
            $auth::STATUS_REVOKED => 'danger',
        ];
        $reasonLabel = [
            $rollout::REASON_DOCTOR_NOT_LINKED => 'Belum terhubung ke akun pengguna',
            $rollout::REASON_DOCTOR_INACTIVE => 'Data dokter nonaktif',
        ];
    @endphp

    <x-ui.card>
        <h3 class="text-base font-semibold text-ink">Langkah 4 — Authorization Dokter</h3>

        @error('doctor_ids')<x-ui.alert variant="danger" class="mt-4">{{ $message }}</x-ui.alert>@enderror

        @if (! $device->isActive())
            <x-ui.alert variant="warning" class="mt-4">
                <span>
                    <strong>Device belum disetujui.</strong>
                    Otorisasi dokter baru dapat diajukan setelah pendaftaran perangkat disetujui.
                    <a class="font-semibold underline"
                       href="{{ route('settings.doctor-device-registration.approval', $device) }}">
                        Kembali ke Approval Device
                    </a>
                </span>
            </x-ui.alert>
        @else
            <x-ui.alert variant="info" class="mt-4">
                Permintaan yang diajukan di sini berstatus <strong>pending</strong> dan harus disetujui pada
                <a class="font-semibold underline" href="{{ route('doctor-device-authorizations.index') }}">Approval Device Dokter</a>.
                Halaman ini tidak pernah menyetujui permintaannya sendiri.
            </x-ui.alert>
        @endif

        <form method="POST" action="{{ route('settings.doctor-device-registration.doctors.store', $device) }}" class="mt-5">
            @csrf

            <div class="overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th class="w-10"></th>
                            <th>Dokter</th>
                            <th>Status Otorisasi</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($candidates as $candidate)
                            <tr>
                                <td>
                                    <input type="checkbox" name="doctor_ids[]" value="{{ $candidate['doctor_id'] }}"
                                           class="rounded border-hairline text-brand-600 focus:ring-brand-500"
                                           @disabled(! $candidate['selectable'] || ! $canRequest || ! $device->isActive())>
                                </td>
                                <td class="font-medium text-ink">{{ $candidate['name'] }}</td>
                                <td>
                                    @if ($candidate['authorization'])
                                        <x-ui.badge :tone="$statusTone[$candidate['authorization']->status] ?? 'neutral'">
                                            {{ $candidate['authorization']->status }}
                                        </x-ui.badge>
                                    @else
                                        <span class="text-sm text-ink-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-sm text-ink-soft">
                                    @if ($candidate['ineligible_reason'])
                                        {{ $reasonLabel[$candidate['ineligible_reason']] ?? $candidate['ineligible_reason'] }}
                                    @elseif ($candidate['authorization'])
                                        Sudah memiliki catatan otorisasi pada perangkat ini.
                                    @else
                                        Dapat diajukan.
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-ui.empty-state title="Tidak ada dokter"
                                description="Belum ada akun dokter yang dapat diotorisasi." /></td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </div>

            @if ($canRequest && $device->isActive())
                <div class="mt-5">
                    <x-ui.button type="submit" variant="primary">Ajukan Otorisasi Dokter</x-ui.button>
                </div>
            @elseif (! $canRequest)
                <x-ui.alert variant="warning" class="mt-5">
                    <span>
                        <strong>{{ App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationWorkflowService::WAITING_ON_AUTHORIZATION_APPROVER }}.</strong>
                        Mengajukan otorisasi dokter memerlukan wewenang <em>manage_doctor_device_authorizations</em>.
                    </span>
                </x-ui.alert>
            @endif
        </form>

        <div class="mt-5">
            <x-ui.button variant="secondary" :href="route('settings.doctor-device-registration.login-test', $device)">
                Lanjut ke Uji Login
            </x-ui.button>
        </div>
    </x-ui.card>
</x-doctor-device-registration-shell>
