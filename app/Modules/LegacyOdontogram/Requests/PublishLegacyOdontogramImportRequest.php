<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Requests;

use App\Modules\LegacyOdontogram\Services\LegacyOdontogramFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FIX-04b — publish a reviewed staging batch into an immutable record.
 *
 * The ONLY operator input is a human-readable title and note. The patient, the
 * branch, the clinical date, the file and its hash all come from the staged row
 * and are re-validated server-side — a publish request cannot introduce or
 * change a single clinical fact.
 *
 * The migration capability is re-checked here as well as in the controller: a
 * FormRequest runs before the controller body, so a disabled capability 404s
 * before any of this is even parsed.
 */
class PublishLegacyOdontogramImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        abort_unless(app(LegacyOdontogramFeatureGuard::class)->migrationEnabled(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'judul arsip',
            'description' => 'keterangan arsip',
        ];
    }

    /**
     * @return array{title: string|null, description: string|null}
     */
    public function archiveAttributes(): array
    {
        return [
            'title' => $this->string('title')->toString() ?: null,
            'description' => $this->string('description')->toString() ?: null,
        ];
    }
}
