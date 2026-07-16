<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;

/**
 * Read-only production-readiness reporter (SATUSEHAT-3). Reports each readiness
 * category with an honest status — WITHOUT enabling anything. External items are
 * expected `blocked_external`; internal dental implementation is expected
 * `ready_internal`. It never marks external readiness as complete and performs
 * no network call. PII-free.
 *
 * category status ∈ not_started | blocked_external | in_progress | ready_internal
 *                   | verified_external
 */
class SatusehatProductionReadinessService
{
    public function __construct(
        private readonly SatusehatProductionActivationGuard $guard,
    ) {}

    /**
     * @return array{
     *   environment:string,
     *   production_allowed:bool,
     *   guard:array<string,mixed>,
     *   categories:list<array{key:string,label:string,kind:string,status:string,detail:string}>,
     *   summary:array{ready_internal:int,in_progress:int,blocked_external:int,not_started:int,total:int}
     * }
     */
    public function report(): array
    {
        $guard = $this->guard->evaluate();
        $env = (string) config('satusehat.environment');
        $categories = [];

        foreach ((array) config('satusehat_dental.production_readiness', []) as $def) {
            $status = $this->resolveStatus($def);
            $categories[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'kind' => $def['kind'],
                'status' => $status,
                'detail' => $this->detail($def, $status),
            ];
        }

        return [
            'environment' => $env,
            'production_allowed' => $guard['allowed'],
            'guard' => $guard,
            'categories' => $categories,
            'summary' => $this->summarize($categories),
        ];
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function resolveStatus(array $def): string
    {
        // Internal categories are evaluated from real internal state; external
        // categories stay at their honest expected (blocked/not_started) posture
        // until a credential/approval sprint verifies them externally.
        if ($def['kind'] === 'external') {
            return (string) $def['expected'];
        }

        return match ($def['key']) {
            'terminology_readiness' => $this->terminologyStatus(),
            default => (string) $def['expected'],
        };
    }

    private function terminologyStatus(): string
    {
        $env = (string) config('satusehat.environment');
        $family = (string) config('satusehat_dental.profile_family', 'dental');

        $activeVerified = SatusehatCodeMapping::query()
            ->where('environment', $env)
            ->where('profile_family', $family)
            ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
            ->whereNotNull('verified_at')
            ->exists();

        // Dental mappings are seeded as DRAFT pending human verification, so
        // until at least one is verified+active this is honestly in_progress.
        return $activeVerified ? 'ready_internal' : 'in_progress';
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function detail(array $def, string $status): string
    {
        return match ($status) {
            'blocked_external' => 'Menunggu langkah eksternal (kredensial/approval Kemkes).',
            'not_started' => 'Belum dimulai — sprint aktivasi produksi terpisah.',
            'in_progress' => 'Sebagian siap — perlu verifikasi/aktivasi internal lanjutan.',
            'ready_internal' => 'Siap secara internal.',
            'verified_external' => 'Terverifikasi eksternal.',
            default => $status,
        };
    }

    /**
     * @param  list<array{status:string}>  $categories
     * @return array{ready_internal:int,in_progress:int,blocked_external:int,not_started:int,total:int}
     */
    private function summarize(array $categories): array
    {
        $counts = ['ready_internal' => 0, 'in_progress' => 0, 'blocked_external' => 0, 'not_started' => 0];
        foreach ($categories as $c) {
            if (array_key_exists($c['status'], $counts)) {
                $counts[$c['status']]++;
            }
        }
        $counts['total'] = count($categories);

        return $counts;
    }
}
