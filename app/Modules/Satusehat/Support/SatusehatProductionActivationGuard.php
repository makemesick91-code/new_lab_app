<?php

namespace App\Modules\Satusehat\Support;

/**
 * Permanent production activation guard. The production SATUSEHAT adapter can
 * ONLY ever be considered activatable when EVERY condition here passes. In
 * SATUSEHAT-3 they all fail by design (no SATUSEHAT-2 GO, no credentials, no
 * approval) and tests assert production cannot activate. This class performs NO
 * side effect and opens NO socket — it only inspects config.
 */
final class SatusehatProductionActivationGuard
{
    /**
     * @return array{allowed:bool, checks:list<array{key:string, label:string, passed:bool, detail:string}>, blockers:list<string>}
     */
    public function evaluate(): array
    {
        $checks = [];

        $checks[] = $this->check('satusehat2_go', 'SATUSEHAT-2 sandbox GO exists',
            (bool) config('satusehat.sandbox_verified'),
            'Sandbox round-trip belum terverifikasi (SATUSEHAT-2 masih WATCH).');

        $checks[] = $this->check('production_enabled_flag', 'Production enable flag on',
            (bool) config('satusehat.production_enabled'),
            'SATUSEHAT_PRODUCTION_ENABLED=false.');

        $checks[] = $this->check('production_approved', 'Production explicitly approved',
            (bool) config('satusehat.production_approved'),
            'SATUSEHAT_PRODUCTION_APPROVED=false.');

        $checks[] = $this->check('approval_reference', 'Approval reference present',
            filled(config('satusehat.production_approval_reference')),
            'Referensi persetujuan produksi belum diisi.');

        $checks[] = $this->check('environment_production', 'Environment is production',
            config('satusehat.environment') === 'production',
            'Environment bukan production.');

        $checks[] = $this->check('credentials_present', 'Production credentials present',
            filled(config('satusehat.client_id')) && filled(config('satusehat.client_secret')),
            'Kredensial produksi belum tersedia.');

        $checks[] = $this->check('organization_location', 'Organization + Location verified',
            filled(config('satusehat.organization_id')) && filled(config('satusehat.location_id')),
            'Organization/Location id produksi belum tersedia.');

        $checks[] = $this->check('master_switch', 'Master switch + send enabled',
            (bool) config('satusehat.enabled') && (bool) config('satusehat.send_enabled'),
            'SATUSEHAT_ENABLED / SATUSEHAT_SEND_ENABLED=false.');

        $blockers = array_values(array_map(
            fn (array $c) => $c['detail'],
            array_filter($checks, fn (array $c) => ! $c['passed']),
        ));

        return [
            'allowed' => $blockers === [],
            'checks' => $checks,
            'blockers' => $blockers,
        ];
    }

    public function isProductionAllowed(): bool
    {
        return $this->evaluate()['allowed'];
    }

    /**
     * @return array{key:string, label:string, passed:bool, detail:string}
     */
    private function check(string $key, string $label, bool $passed, string $failDetail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'detail' => $passed ? 'OK' : $failDetail,
        ];
    }
}
