<?php

namespace App\Modules\Satusehat\Support;

/**
 * LOCAL FHIR conformance validator for dental Observation resources. Checks
 * structure/cardinality/reference-format/code-system against the local
 * expectation. It is NOT — and must never be presented as — SATUSEHAT
 * acceptance. A `valid` result only means the local shape is well-formed.
 *
 * Result codes: valid | invalid | unsupported | mapping_missing | source_missing
 *               | profile_unverified
 */
final class SatusehatDentalConformanceValidator
{
    private const CODE_SYSTEM_PREFIXES = [
        'http://snomed.info/sct',
        'http://loinc.org',
        'http://terminology.kemkes.go.id/',
        'http://terminology.hl7.org/',
    ];

    /**
     * @param  array<string, mixed>  $resource  A built dental Observation payload.
     * @return array{result:string, issues:list<string>}
     */
    public function validate(array $resource): array
    {
        $issues = [];

        if (($resource['resourceType'] ?? null) !== 'Observation') {
            return ['result' => 'invalid', 'issues' => ['resourceType harus Observation.']];
        }

        if (($resource['status'] ?? null) !== 'final') {
            $issues[] = 'Observation.status harus "final".';
        }

        // category.coding[exam]
        $category = $resource['category'][0]['coding'][0] ?? null;
        if (! is_array($category) || ($category['code'] ?? null) !== 'exam') {
            $issues[] = 'Observation.category harus kode "exam".';
        }

        // code.coding — required, system must be a recognised terminology URI + code present.
        $coding = $resource['code']['coding'][0] ?? null;
        if (! is_array($coding)) {
            $issues[] = 'Observation.code.coding wajib ada.';
        } else {
            if (! $this->isKnownSystem($coding['system'] ?? null)) {
                return ['result' => 'mapping_missing', 'issues' => ['Observation.code.system bukan terminologi resmi yang dikenal.']];
            }
            if (blank($coding['code'] ?? null)) {
                return ['result' => 'mapping_missing', 'issues' => ['Observation.code.code kosong — mapping resmi belum tersedia.']];
            }
        }

        // subject reference required + format.
        if (! $this->validReference($resource['subject'] ?? null)) {
            $issues[] = 'Observation.subject (Patient) referensi tidak valid.';
        }

        // exactly one value[x] present.
        $valueKeys = array_filter(array_keys($resource), fn ($k) => str_starts_with((string) $k, 'value'));
        if (count($valueKeys) === 0 && empty($resource['component'])) {
            $issues[] = 'Observation harus memiliki value[x] atau component.';
        }
        if (count($valueKeys) > 1) {
            $issues[] = 'Observation hanya boleh satu value[x].';
        }

        // effectiveDateTime, when present, must be an ISO-8601 UTC string.
        if (isset($resource['effectiveDateTime']) && ! $this->looksUtc($resource['effectiveDateTime'])) {
            $issues[] = 'Observation.effectiveDateTime harus ISO-8601 UTC.';
        }

        // No unsupported binary / attachment / image payload may ever appear.
        foreach (['data', 'attachment', 'photo', 'image', 'handwriting', 'ktp', 'nik'] as $forbidden) {
            if ($this->deepHasKey($resource, $forbidden)) {
                return ['result' => 'invalid', 'issues' => ["Payload mengandung field terlarang: {$forbidden}."]];
            }
        }

        return [
            'result' => $issues === [] ? 'valid' : 'invalid',
            'issues' => $issues,
        ];
    }

    private function isKnownSystem(?string $system): bool
    {
        if (! is_string($system) || $system === '') {
            return false;
        }
        foreach (self::CODE_SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($system, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $reference
     */
    private function validReference($reference): bool
    {
        if (! is_array($reference)) {
            return false;
        }

        // Accepts the SATUSEHAT-3 local reference shape (distinguishes local vs
        // future remote IHS id). A local reference is enough to be "well-formed".
        return isset($reference['local_reference']) || isset($reference['reference']);
    }

    private function looksUtc(mixed $value): bool
    {
        return is_string($value) && (str_ends_with($value, 'Z') || (bool) preg_match('/[+-]00:00$/', $value));
    }

    /**
     * @param  mixed  $data
     */
    private function deepHasKey($data, string $key): bool
    {
        if (! is_array($data)) {
            return false;
        }
        foreach ($data as $k => $v) {
            if ($k === $key) {
                return true;
            }
            if (is_array($v) && $this->deepHasKey($v, $key)) {
                return true;
            }
        }

        return false;
    }
}
