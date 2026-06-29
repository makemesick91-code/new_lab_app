@if (! empty($doctorAccessSummary))
    <x-ui.card title="Dokter yang Memiliki Akses RM">
        <ul class="divide-y divide-gray-100">
            @foreach ($doctorAccessSummary as $row)
                <li class="flex flex-wrap items-start justify-between gap-2 py-3 first:pt-0 last:pb-0">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $row['doctor']->name }}</p>
                        @if ($row['doctor']->code)
                            <p class="text-xs text-gray-500">{{ $row['doctor']->code }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-1">
                        @foreach ($row['badges'] as $badge)
                            @php
                                $tone = match (true) {
                                    $badge === 'Aktif' => 'success',
                                    str_contains($badge, 'Auto') => 'info',
                                    str_contains($badge, 'Shared') || str_contains($badge, 'Reassigned') => 'warning',
                                    default => 'neutral',
                                };
                            @endphp
                            <x-ui.badge :tone="$tone">{{ $badge }}</x-ui.badge>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
@endif
