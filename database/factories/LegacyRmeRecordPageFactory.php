<?php

namespace Database\Factories;

use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A.
 *
 * @extends Factory<LegacyRmeRecordPage>
 */
class LegacyRmeRecordPageFactory extends Factory
{
    protected $model = LegacyRmeRecordPage::class;

    public function definition(): array
    {
        return [
            'rme_legacy_record_id' => LegacyRmeRecord::factory(),
            'page_number' => 1,
            'width' => 1654,
            'height' => 2339,
            'dpi' => 200,
            'rotation' => 0,
            'background_disk' => 'local',
            'background_path' => 'rme-legacy/example/page-1.jpg',
            'background_sha256' => hash('sha256', (string) Str::uuid()),
            'thumbnail_path' => null,
            'normalized_page_hash' => null,
        ];
    }
}
