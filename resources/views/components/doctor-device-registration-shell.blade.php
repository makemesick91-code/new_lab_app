{{--
    DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — the workflow shell.

    Wraps the canonical <x-settings-shell> rather than replacing it, so the
    application layout and the MAIN sidebar are exactly the ones every other
    settings page uses. The sub-sidebar here is an addition inside the content
    area, never a second copy of the app navigation.

    It renders on EVERY page of the workflow, including the read-only ones, so
    the operator can always see where the tablet has got to and jump back to a
    step that failed.

    The step statuses are DERIVED (see DoctorDeviceRegistrationWorkflowService)
    and the "waiting" labels are presentation. Nothing drawn here unlocks
    anything: every mutation re-authorizes server-side.
--}}
@props([
    'device',
    'steps' => [],
    'activeStep' => 'ringkasan',
    'title' => 'Pendaftaran Device Dokter',
])

@php
    $wf = App\Modules\DoctorDevice\Services\DoctorDeviceRegistrationWorkflowService::class;

    $marks = [
        $wf::STATUS_COMPLETE => ['glyph' => '✓', 'class' => 'text-success-700', 'label' => 'Selesai'],
        $wf::STATUS_CURRENT => ['glyph' => '●', 'class' => 'text-brand-700', 'label' => 'Sedang dikerjakan'],
        $wf::STATUS_PENDING => ['glyph' => '○', 'class' => 'text-ink-soft', 'label' => 'Belum dikerjakan'],
        $wf::STATUS_ACTION_REQUIRED => ['glyph' => '!', 'class' => 'text-warning-700', 'label' => 'Perlu tindakan'],
        $wf::STATUS_FAILED => ['glyph' => '✕', 'class' => 'text-danger-700', 'label' => 'Gagal'],
    ];

    $itemBase = 'flex items-start gap-2 rounded-lg px-3 py-2 text-sm transition';
    $itemActive = 'bg-brand-50 font-semibold text-brand-800';
    $itemIdle = 'text-ink-soft hover:bg-navy-50 hover:text-ink';
@endphp

<x-settings-shell :title="$title">
    <div class="grid gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
        {{-- SUB-SIDEBAR — present on every workflow page. --}}
        <nav aria-label="Langkah pendaftaran device" data-registration-subnav
             class="h-max rounded-xl border border-hairline bg-white p-3">
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wide text-ink-muted">
                Pendaftaran Device
            </p>

            <a href="{{ route('settings.doctor-device-registration.show', $device) }}"
               class="{{ $itemBase }} {{ $activeStep === 'ringkasan' ? $itemActive : $itemIdle }}">
                <span class="w-4 shrink-0 text-center">◆</span>
                <span>Ringkasan</span>
            </a>

            <ol class="mt-1 space-y-0.5">
                @foreach ($steps as $step)
                    @php
                        $mark = $marks[$step['status']] ?? $marks[$wf::STATUS_PENDING];
                        $isActive = $activeStep === $step['key'];
                    @endphp
                    <li>
                        <a href="{{ route('settings.doctor-device-registration.' . $step['key'], $device) }}"
                           @class([$itemBase, $itemActive => $isActive, $itemIdle => ! $isActive])
                           aria-current="{{ $isActive ? 'page' : 'false' }}"
                           data-step="{{ $step['key'] }}"
                           data-step-status="{{ $step['status'] }}">
                            <span class="w-4 shrink-0 text-center {{ $mark['class'] }}"
                                  title="{{ $mark['label'] }}"
                                  aria-hidden="true">{{ $mark['glyph'] }}</span>
                            <span class="min-w-0">
                                <span class="block">{{ $step['number'] }}. {{ $step['label'] }}</span>
                                <span class="sr-only">{{ $mark['label'] }}.</span>
                                @if ($step['waiting_for'])
                                    {{-- The other party's turn, said out loud rather than
                                         shown as a disabled button with no explanation. --}}
                                    <span class="mt-0.5 block text-xs font-normal text-warning-700">
                                        {{ $step['waiting_for'] }}
                                    </span>
                                @elseif ($step['detail'])
                                    <span class="mt-0.5 block text-xs font-normal text-ink-muted">
                                        {{ $step['detail'] }}
                                    </span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>

            <a href="{{ route('settings.doctor-device-registration.history', $device) }}"
               class="{{ $itemBase }} mt-1 {{ $activeStep === 'riwayat' ? $itemActive : $itemIdle }}">
                <span class="w-4 shrink-0 text-center">◷</span>
                <span>Riwayat</span>
            </a>

            <div class="mt-3 border-t border-hairline px-3 pt-3">
                <a class="text-xs text-brand-700 hover:underline"
                   href="{{ route('settings.doctor-device-registration.index') }}">
                    ← Semua pendaftaran
                </a>
            </div>
        </nav>

        <div class="min-w-0 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ $device->device_name }}</h2>
                    <p class="text-sm text-ink-soft">{{ $device->branch?->name ?? '—' }}</p>
                </div>
                <x-ui.badge :tone="$device->isActive() ? 'success' : ($device->isPendingApproval() ? 'warning' : 'danger')">
                    {{ $device->status }}
                </x-ui.badge>
            </div>

            @if (session('status'))
                <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
            @endif

            @if (session('success'))
                <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
            @endif

            {{ $slot }}
        </div>
    </div>
</x-settings-shell>
