<x-settings-shell title="Observability Console — Detail Error">
    @php
        $user = $event->user_id ? ($labels['users'][$event->user_id] ?? 'User #'.$event->user_id) : 'Guest';
        $rows = [
            'Waktu' => null,
            'Request ID' => $event->request_id,
            'HTTP status' => $event->status_code.($event->response_status !== $event->status_code ? ' (respons asli '.$event->response_status.')' : ''),
            'User' => $user,
            'Role' => $event->user_role ?? '—',
            'Cabang' => $labels['branches'][$event->branch_id] ?? '—',
            'HTTP method' => $event->method,
            'Route' => $event->route_name ?? '—',
            'Path (template)' => $event->path_template,
            'Aktivitas' => $event->activity,
            'Durasi' => number_format($event->duration_ms).' ms (DB '.number_format($event->db_time_ms).' ms · '.$event->query_count.' query)',
            'Exception' => $event->exception_class ?? '—',
            'Pesan (disanitasi)' => $event->exception_message ?? '—',
            'File' => $event->exception_file ? $event->exception_file.':'.$event->exception_line : '—',
        ];
    @endphp
    <div class="space-y-6">
        <x-ui.page-header title="Detail Error" subtitle="Informasi troubleshooting yang aman. Isi request, cookie, token, dan nilai binding SQL tidak pernah disimpan.">
            <x-slot name="actions"><x-ui.button variant="secondary" :href="route('developer-console.errors')">Kembali</x-ui.button></x-slot>
        </x-ui.page-header>
        @include('dev-console.partials.nav')

        <x-ui.card>
            <dl class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-[12rem_1fr]">
                @foreach ($rows as $label => $value)
                    <dt class="font-medium text-ink-soft">{{ $label }}</dt>
                    <dd class="break-words font-mono text-xs text-navy">
                        @if ($label === 'Waktu')
                            <x-dev-console.time :at="$event->occurred_at" />
                        @else
                            {{ $value }}
                        @endif
                    </dd>
                @endforeach
            </dl>
        </x-ui.card>

        @if (! empty($event->trace_summary))
            <x-ui.card title="Ringkasan trace" description="Maksimal 8 frame, path relatif, tanpa argumen fungsi. Hanya untuk error 5xx.">
                <ol class="list-decimal space-y-1 pl-5 font-mono text-xs text-ink-soft">
                    @foreach ($event->trace_summary as $frame)
                        <li>{{ $frame }}</li>
                    @endforeach
                </ol>
            </x-ui.card>
        @endif

        @if ($queries->isNotEmpty())
            <x-ui.card title="Slow query pada request ini" description="SQL ternormalisasi — nilai binding: [REDACTED].">
                <ul class="space-y-2 text-xs">
                    @foreach ($queries as $q)
                        <li class="rounded-lg bg-navy-50 p-2"><span class="font-semibold text-navy">{{ number_format($q->duration_ms) }} ms</span> · <code class="break-all">{{ $q->sql_normalized }}</code></li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
