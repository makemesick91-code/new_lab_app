<?php

namespace App\Modules\Odontogram\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOdontogramPlaceholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Decode JSON string submitted by the Alpine.js hidden input
        if ($this->has('tooth_map_payload') && is_string($this->input('tooth_map_payload'))) {
            $decoded = json_decode($this->input('tooth_map_payload'), true);
            if (is_array($decoded)) {
                $this->merge(['tooth_map_payload' => $decoded]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'summary_notes' => ['nullable', 'string', 'max:5000'],
            'additional_conditions' => ['nullable', 'string', 'max:5000'],
            'tooth_map_payload' => ['nullable', 'array'],
            'tooth_map_payload.teeth' => ['nullable', 'array'],
            'tooth_map_payload.teeth.*' => ['nullable', 'array'],
            // Hotfix Sprint 60.3 — `filling` added so the F (Filling) DMF-T
            // component is selectable directly from the DIAGNOSA dropdown.
            'tooth_map_payload.teeth.*.status' => ['nullable', 'string', 'in:normal,caries,missing,filling,crown,root_treated'],
            'tooth_map_payload.teeth.*.note' => ['nullable', 'string', 'max:1000'],
            'tooth_map_payload.teeth.*.conditions' => ['nullable', 'array'],
            'tooth_map_payload.teeth.*.conditions.*' => ['nullable', 'string', 'in:caries,missing,crown,root_treated,mobility,impaction,filling'],
            // Per-selected-row fields. additional_condition/additional_note are the
            // legacy keys reused for the DIAGNOSA/PERAWATAN columns (Phase 23.10.4);
            // `dokter` is the new DOKTER column (Hotfix Sprint 60.3, additive).
            'tooth_map_payload.teeth.*.additional_condition' => ['nullable', 'string', 'max:1000'],
            'tooth_map_payload.teeth.*.additional_note' => ['nullable', 'string', 'max:1000'],
            'tooth_map_payload.teeth.*.dokter' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $payload = $this->input('tooth_map_payload');
            $teeth = is_array($payload) ? ($payload['teeth'] ?? null) : null;

            if (! is_array($teeth)) {
                return;
            }

            $valid = $this->validFdiNumbers();
            foreach (array_keys($teeth) as $toothNum) {
                if (! in_array((string) $toothNum, $valid, true)) {
                    $v->errors()->add(
                        'tooth_map_payload.teeth',
                        "Nomor gigi '{$toothNum}' tidak valid. Gunakan nomor FDI (11–18, 21–28, 31–38, 41–48)."
                    );
                }
            }

            foreach ($teeth as $toothNum => $toothData) {
                if (! isset($toothData['conditions']) || ! is_array($toothData['conditions'])) {
                    continue;
                }
                $conditions = array_values(array_filter($toothData['conditions'], fn ($c) => $c !== null));
                if (count($conditions) !== count(array_unique($conditions))) {
                    $v->errors()->add(
                        "tooth_map_payload.teeth.{$toothNum}.conditions",
                        "Gigi '{$toothNum}' memiliki kondisi duplikat."
                    );
                }
            }
        });
    }

    /**
     * Valid FDI numbers per the Daengtisia odontogram (Hotfix Sprint 60.3).
     * Permanent quadrants 1–4 (teeth 1–8) and primary quadrants 5–8 (teeth 1–5):
     *   Permanent: 11–18, 21–28, 31–38, 41–48
     *   Primary:   51–55, 61–65, 71–75, 81–85
     *
     * @return string[]
     */
    private function validFdiNumbers(): array
    {
        $numbers = [];
        foreach ([1, 2, 3, 4] as $quadrant) {
            for ($i = 1; $i <= 8; $i++) {
                $numbers[] = (string) ($quadrant * 10 + $i);
            }
        }
        foreach ([5, 6, 7, 8] as $quadrant) {
            for ($i = 1; $i <= 5; $i++) {
                $numbers[] = (string) ($quadrant * 10 + $i);
            }
        }

        return $numbers;
    }
}
