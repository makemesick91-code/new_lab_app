<x-settings-shell title="Observability Console — Cache / Redis">
    @php
        $app = $snapshot['app'];
        $redis = $snapshot['redis'];
        $redisTone = match ($redis['status']) {
            'CONNECTED' => 'success',
            'UNAVAILABLE' => 'danger',
            default => 'neutral',
        };
    @endphp
    <div class="space-y-6">
        <x-ui.page-header title="Cache / Redis" subtitle="Dua angka berbeda yang tidak pernah dicampur: hit rate APLIKASI (event cache Laravel pada request DaengtisiaMS) dan hit rate SERVER Redis (seluruh server, bisa termasuk aplikasi lain)." />
        @include('dev-console.partials.nav')

        <x-ui.card title="Backend">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">Cache driver</dt><dd class="font-mono text-navy">{{ $snapshot['cache_driver'] }}</dd></div>
                <div><dt class="text-ink-soft">Session driver</dt><dd class="font-mono text-navy">{{ $snapshot['session_driver'] }}</dd></div>
                <div><dt class="text-ink-soft">Queue connection</dt><dd class="font-mono text-navy">{{ $snapshot['queue_connection'] }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="DaengtisiaMS Cache Hit Rate" description="{{ $windowHours }} jam terakhir · dihitung dari {{ number_format($app['requests']) }} request tercatat · hits / (hits + misses) × 100.">
            <div class="grid grid-cols-3 gap-4">
                <x-ui.kpi-card label="Hit rate" :value="$app['hit_rate'] === null ? 'N/A' : $app['hit_rate'].'%'" />
                <x-ui.kpi-card label="Hits" :value="number_format($app['hits'])" />
                <x-ui.kpi-card label="Misses" :value="number_format($app['misses'])" />
            </div>
            @if ($app['hit_rate'] === null)
                <p class="mt-3 text-xs text-ink-soft">N/A berarti belum ada lookup cache tercatat — bukan 0% efektif.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Redis Server" description="Hit rate SERVER Redis (keyspace_hits / keyspace_misses) mencakup seluruh database dan aplikasi pada server tersebut.">
            <div class="flex items-center gap-3 text-sm">
                <x-ui.badge :tone="$redisTone">{{ $redis['status'] }}</x-ui.badge>
                @if ($redis['reason'])
                    <span class="text-ink-soft">{{ $redis['reason'] }}</span>
                @endif
            </div>
            @if ($redis['status'] === 'CONNECTED')
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                    <div><dt class="text-ink-soft">Redis Server Hit Rate</dt><dd class="font-semibold text-navy">{{ $redis['hit_rate'] === null ? 'N/A' : $redis['hit_rate'].'%' }}</dd></div>
                    <div><dt class="text-ink-soft">Keyspace hits</dt><dd class="text-navy">{{ number_format($redis['keyspace_hits']) }}</dd></div>
                    <div><dt class="text-ink-soft">Keyspace misses</dt><dd class="text-navy">{{ number_format($redis['keyspace_misses']) }}</dd></div>
                    <div><dt class="text-ink-soft">Memory</dt><dd class="text-navy">{{ $redis['used_memory_human'] ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft">Connected clients</dt><dd class="text-navy">{{ $redis['connected_clients'] ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft">Evicted keys</dt><dd class="text-navy">{{ $redis['evicted_keys'] ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft">Expired keys</dt><dd class="text-navy">{{ $redis['expired_keys'] ?? '—' }}</dd></div>
                </dl>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
