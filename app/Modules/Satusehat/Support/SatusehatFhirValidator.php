<?php

namespace App\Modules\Satusehat\Support;

/**
 * Local, pre-flight structural validator for a built FHIR resource. It is a
 * cheap guard — NOT a claim of remote acceptance. It asserts mandatory paths,
 * well-formed references, UTC timestamps, presence of terminology, and the
 * ABSENCE of out-of-scope/sensitive content (odontogram, scan, handwriting,
 * attachments, raw NIK). Returns a list of issue strings; empty means it passed
 * the local structural checks.
 */
final class SatusehatFhirValidator
{
    /** Keys that must NEVER appear anywhere in a submitted payload. */
    private const FORBIDDEN_KEYS = [
        'odontogram', 'handwriting', 'handwriting_path', 'scan', 'scanned',
        'attachment', 'photo', 'image', 'ktp', 'ktp_number', 'nik',
        'subject_nik_masked', 'local_reference', 'remote_ihs_identifier',
    ];

    /**
     * @param  array<string, mixed>  $resource
     * @return list<string>
     */
    public function validate(array $resource): array
    {
        $issues = [];

        $type = $resource['resourceType'] ?? null;
        if (! is_string($type) || $type === '') {
            return ['resourceType wajib diisi.'];
        }

        $forbidden = $this->findForbiddenKeys($resource);
        foreach ($forbidden as $key) {
            $issues[] = "Payload memuat field terlarang: {$key}.";
        }

        $issues = array_merge($issues, match ($type) {
            'Encounter' => $this->validateEncounter($resource),
            'Condition' => $this->validateCondition($resource),
            'Procedure' => $this->validateProcedure($resource),
            default => ["Resource type {$type} tidak didukung."],
        });

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    private function validateEncounter(array $r): array
    {
        $issues = [];
        if (($r['status'] ?? null) === null) {
            $issues[] = 'Encounter.status wajib.';
        }
        if (! $this->isReference($r['subject'] ?? null, 'Patient')) {
            $issues[] = 'Encounter.subject harus referensi Patient/{ihs}.';
        }
        if (! $this->hasReferenceInList($r['participant'] ?? [], 'individual', 'Practitioner')) {
            $issues[] = 'Encounter.participant harus memuat Practitioner/{ihs}.';
        }
        if (! $this->isReference($r['serviceProvider'] ?? null, 'Organization')) {
            $issues[] = 'Encounter.serviceProvider harus referensi Organization/{id}.';
        }
        $start = $r['period']['start'] ?? null;
        if (! $this->isUtcInstant($start)) {
            $issues[] = 'Encounter.period.start harus timestamp UTC ISO-8601.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    private function validateCondition(array $r): array
    {
        $issues = [];
        if (! $this->hasCoding($r['code'] ?? null)) {
            $issues[] = 'Condition.code.coding (system+code) wajib.';
        }
        if (! $this->isReference($r['subject'] ?? null, 'Patient')) {
            $issues[] = 'Condition.subject harus referensi Patient/{ihs}.';
        }
        if (! $this->isReference($r['encounter'] ?? null, 'Encounter')) {
            $issues[] = 'Condition.encounter harus referensi Encounter/{id}.';
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<string>
     */
    private function validateProcedure(array $r): array
    {
        $issues = [];
        if (($r['status'] ?? null) === null) {
            $issues[] = 'Procedure.status wajib.';
        }
        if (! $this->hasCoding($r['code'] ?? null)) {
            $issues[] = 'Procedure.code.coding (system+code) wajib.';
        }
        if (! $this->isReference($r['subject'] ?? null, 'Patient')) {
            $issues[] = 'Procedure.subject harus referensi Patient/{ihs}.';
        }
        if (! $this->isReference($r['encounter'] ?? null, 'Encounter')) {
            $issues[] = 'Procedure.encounter harus referensi Encounter/{id}.';
        }

        return $issues;
    }

    /**
     * @param  mixed  $ref
     */
    private function isReference($ref, string $expectedType): bool
    {
        if (! is_array($ref) || ! isset($ref['reference']) || ! is_string($ref['reference'])) {
            return false;
        }

        return preg_match('#^'.preg_quote($expectedType, '#').'/[A-Za-z0-9\-\.]{1,128}$#', $ref['reference']) === 1;
    }

    /**
     * @param  mixed  $list
     */
    private function hasReferenceInList($list, string $wrapperKey, string $expectedType): bool
    {
        if (! is_array($list)) {
            return false;
        }
        foreach ($list as $entry) {
            if (is_array($entry) && $this->isReference($entry[$wrapperKey] ?? null, $expectedType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $code
     */
    private function hasCoding($code): bool
    {
        $coding = is_array($code) ? ($code['coding'][0] ?? null) : null;

        return is_array($coding)
            && filled($coding['system'] ?? null)
            && filled($coding['code'] ?? null);
    }

    /**
     * @param  mixed  $value
     */
    private function isUtcInstant($value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[.\d]+)?(?:Z|\+00:00)$/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function findForbiddenKeys(array $data): array
    {
        $found = [];
        array_walk_recursive($data, function ($value, $key) use (&$found) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                $found[] = strtolower($key);
            }
        });

        // array_walk_recursive only visits leaf keys; also scan array keys.
        $this->scanKeys($data, $found);

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $found
     */
    private function scanKeys(array $data, array &$found): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                $found[] = strtolower($key);
            }
            if (is_array($value)) {
                $this->scanKeys($value, $found);
            }
        }
    }
}
