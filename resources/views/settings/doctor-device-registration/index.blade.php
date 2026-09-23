{{--
    DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — the board.

    Which tablets are mid-registration and where each has got to. Every column
    is DERIVED from the device's real state, so a row cannot claim a step is
    finished after somebody revokes a credential from the device page.
--}}
<x-settings-shell title="Pendaftaran Device Dokter">
    @php
        $wf = App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationWorkflowService::class;

        $tone = [
            $wf::STATUS_COMPLETE => 'success',
            $wf::STATUS_CURRENT => 'primary',
            $wf::STATUS_PENDING => 'neutral',
            $wf::STATUS_ACTION_REQUIRED => 'warning',
            $wf::STATUS_FAILED => 'danger',
        ];
        $glyph = [
            $wf::STATUS_COMPLETE => '✓',
            $wf::STATUS_CURRENT => '●',
            $wf::STATUS_PENDING => '○',
            $wf::STATUS_ACTION_REQUIRED => '!',
            $wf::STATUS_FAILED => '✕',
        ];
    @endphp

    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">Pendaftaran Device Dokter</h2>
                <p class="text-sm text-ink-soft">
                    Panduan bertahap untuk mendaftarkan tablet klinik baru sampai benar-benar siap dipakai login.
                </p>
            </div>
            @can('register', App\Modules\DoctorDevice\Models\DoctorDevice::class)
                <x-ui.button variant="primary" :href="route('settings.doctor-device-registration.create')">
                    Daftarkan Device Baru
                </x-ui.button>
            @endcan
        </div>

        <x-ui.alert variant="info" class="mt-4">
            Pendaftaran perangkat <strong>tidak</strong> langsung memberi akses. Perangkat baru berstatus
            <em>pending_approval</em> dan ditolak oleh gerbang login sampai disetujui, kredensialnya terdaftar,
            dan minimal satu dokter diotorisasi.
        </x-ui.alert>

        <form method="GET" class="mt-4 flex flex-wrap gap-2">
            <input type="search" name="search" value="{{ $search }}"
                   placeholder="Cari nama perangkat"
                   class="w-64 rounded-lg border-hairline text-sm focus:border-brand-500">
            <x-ui.button type="submit" variant="secondary" size="sm">Cari</x-ui.button>
        </form>

        @if ($rows->isEmpty())
            <x-ui.empty-state class="mt-6" title="Belum ada perangkat"
                              description="Mulai dengan mendaftarkan tablet klinik pertama." />
        @else
            <div class="mt-5 overflow-x-auto">
                <x-ui.table>
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Cabang</th>
                            <th>Langkah Saat Ini</th>
                            <th>WebAuthn</th>
                            <th>Approval</th>
                            <th>Dokter</th>
                            <th>Uji Login</th>
                            <th>Readiness</th>
                            <th>Diperbarui</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $steps = collect($row['steps'])->keyBy('key');
                                $current = $steps->get($row['current_step']);
                            @endphp
                            <tr>
                                <td class="font-medium text-ink">{{ $row['device']->device_name }}</td>
                                <td>{{ $row['device']->branch?->name ?? '—' }}</td>
                                <td>
                                    <x-ui.badge :tone="$tone[$current['status'] ?? $wf::STATUS_PENDING] ?? 'neutral'">
                                        {{ $current['number'] ?? '' }}. {{ $current['label'] ?? '—' }}
                                    </x-ui.badge>
                                </td>
                                @foreach ([$wf::STEP_WEBAUTHN, $wf::STEP_APPROVAL, $wf::STEP_DOCTORS, $wf::STEP_LOGIN_TEST, $wf::STEP_READINESS] as $key)
                                    @php $s = $steps->get($key); @endphp
                                    <td class="text-center">
                                        <span class="text-base" title="{{ $s['detail'] ?? '' }}">
                                            {{ $glyph[$s['status']] ?? '○' }}
                                        </span>
                                    </td>
                                @endforeach
                                <td class="text-sm text-ink-soft">
                                    {{ $row['device']->updated_at?->format('d/m/Y H:i') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <x-ui.button size="sm" variant="secondary"
                                                 :href="route('settings.doctor-device-registration.show', $row['device'])">
                                        {{ $row['is_ready'] ? 'Lihat' : 'Lanjutkan' }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            </div>

            <div class="mt-4">{{ $devices->withQueryString()->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
