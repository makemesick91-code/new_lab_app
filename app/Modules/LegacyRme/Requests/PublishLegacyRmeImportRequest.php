<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Requests;

use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY-RME-PDF-1C — the publish confirmation payload.
 *
 * DELIBERATELY TINY. The only client-supplied values are two optional archive
 * labels. Everything that decides whether the publish may happen — the patient,
 * the branch, the historical date, the source file, the rendered pages, the
 * status — is read server-side from the staged import, never from the request.
 * A forged patient_id, branch_id, date or storage path therefore has nowhere to
 * land.
 *
 * Authorization stays with the route middleware and the policy
 * (LegacyRmeImportPolicy::publish), which is where the per-row branch scope and
 * the transition gate live; this request only shapes input.
 */
class PublishLegacyRmeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The archive is behind a feature flag that defaults to OFF, and while it is
     * off the capability must not exist at all.
     *
     * The check lives here as well as in the controller because a FormRequest
     * validates BEFORE the action body runs: without it, a malformed payload
     * would answer 422 rather than 404 and so weakly confirm that the disabled
     * endpoint is there.
     */
    protected function prepareForValidation(): void
    {
        abort_unless(app(LegacyRmeFeatureGuard::class)->enabled(), 404);
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
