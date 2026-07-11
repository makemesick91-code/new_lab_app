<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesSprintContext;
use App\Support\Devflow\SprintEvidenceGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * DEVFLOW-1 — generate a sprint evidence pack from REAL sources.
 */
final class SprintEvidenceCommand extends Command
{
    use ResolvesSprintContext;

    protected $signature = 'sprint:evidence
        {--manifest= : Path to the sprint manifest}
        {--from-log= : Path to a deploy/smoke log to fold into the evidence}
        {--decision= : Overall GO/WATCH/NO-GO decision from the release}
        {--pr= : PR reference}
        {--json : Output JSON}
        {--markdown : Output Markdown}
        {--write : Persist evidence under the configured evidence roots}';

    protected $description = 'Assemble a sprint evidence pack (real values only; missing = NOT AVAILABLE).';

    public function handle(SprintEvidenceGenerator $generator): int
    {
        $manifest = $this->loadManifest();
        if ($manifest === null) {
            $this->error('No readable manifest at '.$this->manifestPath());

            return self::FAILURE;
        }

        $extra = [];
        foreach (['decision', 'pr'] as $opt) {
            $val = $this->option($opt);
            if (is_string($val) && $val !== '') {
                $extra[$opt] = $val;
            }
        }

        $logPath = $this->option('from-log');
        if (is_string($logPath) && $logPath !== '' && is_file($this->toAbsolute($logPath))) {
            $tail = $this->tail($this->toAbsolute($logPath), 40);
            $extra['logs'] = $tail;
        }

        $evidence = $generator->build($manifest, $extra);
        $markdown = $generator->toMarkdown($evidence);

        if ($this->option('json')) {
            $this->line((string) json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($this->option('markdown')) {
            $this->line($markdown);
        } else {
            $this->line($markdown);
        }

        if ($this->option('write')) {
            $this->persist($manifest->id() ?? 'sprint', $evidence, $markdown);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function persist(string $id, array $evidence, string $markdown): void
    {
        $logRoot = base_path((string) config('devflow.evidence.log_root')).'/'.$id;
        File::ensureDirectoryExists($logRoot);
        $stamp = date('Ymd-His');
        File::put($logRoot."/evidence-{$stamp}.json", (string) json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($logRoot.'/latest.json', (string) json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $docRoot = base_path((string) config('devflow.evidence.doc_root'));
        File::ensureDirectoryExists($docRoot);
        File::put($docRoot.'/'.strtolower($id).'-evidence.md', $markdown);

        $this->info("Evidence written to {$logRoot} and {$docRoot}.");
    }

    private function tail(string $path, int $lines): string
    {
        $content = (string) file_get_contents($path);
        $all = preg_split('/\R/', $content) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
