<?php

/**
 * REVISION-PATIENT-KTP-OCR-FIELD-BASED-ROI-1 — benchmark bridge (dev only).
 *
 * Feeds OCR output from the Node benchmark through the REAL server-side parse
 * pipeline, so the benchmark measures what an operator would actually be
 * offered rather than a re-implementation of it.
 *
 * stdin:  {"items": [{"id": "...", "lines": [...], "fields": {...}|null}]}
 * stdout: {"results": {"<id>": <KtpOcrSuggestionService::suggest() payload>}}
 *
 * Never run against production: it boots the local application only to
 * resolve the parser from the container, and touches no database or file.
 */

use App\Modules\Patient\Services\KtpOcrParser;
use App\Modules\Patient\Services\KtpOcrSuggestionService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode((string) stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$threshold = (float) config('scanner.ocr.confidence_threshold', 75.0);
// The baseline (pre-ROI) checkout has only the line parser; measure it as-is.
$service = class_exists(KtpOcrSuggestionService::class) ? $app->make(KtpOcrSuggestionService::class) : null;
$parser = $app->make(KtpOcrParser::class);

$results = [];
foreach ($input['items'] ?? [] as $item) {
    $results[$item['id']] = $service !== null
        ? $service->suggest($item['lines'] ?? [], $item['fields'] ?? null, $threshold)
        : $parser->parse($item['lines'] ?? [], $threshold);
}

echo json_encode(['results' => $results], JSON_THROW_ON_ERROR);
