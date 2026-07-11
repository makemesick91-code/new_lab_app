<?php

declare(strict_types=1);

namespace App\Support\Devflow;

/**
 * DEVFLOW-1 — Focused test plan resolver.
 *
 * Given a changed-file set (and optionally a manifest), resolves the minimal
 * but safe set of tests to run, plus the CI escalation, by walking
 * config/sprint_regression_matrix.php. Fail-closed: any unmatched changed
 * file, or an unresolved diff, escalates to the full required suite.
 *
 * Deterministic and side-effect free — it plans, it does not run tests.
 */
final class SprintTestPlanner
{
    /**
     * @param  list<string>  $changedFiles
     * @param  bool  $diffResolved  false when the change set could not be resolved (=> escalate)
     * @return array{
     *   changed_files:list<string>,
     *   matched_categories:list<string>,
     *   related_categories:list<string>,
     *   all_categories:list<string>,
     *   unmatched_files:list<string>,
     *   focused_filters:list<string>,
     *   test_paths:list<string>,
     *   ci_jobs:list<string>,
     *   escalate_full_suite:bool,
     *   escalation_reasons:list<string>,
     *   deterministic:bool
     * }
     */
    public function plan(array $changedFiles, bool $diffResolved = true, ?SprintManifest $manifest = null): array
    {
        $matrix = (array) config('sprint_regression_matrix.categories', []);
        $fullEscalationCats = (array) config('sprint_regression_matrix.full_suite_escalation_categories', []);

        $matched = [];
        $unmatched = [];

        foreach ($changedFiles as $file) {
            $fileCats = $this->categoriesForFile($file, $matrix);
            if ($fileCats === []) {
                $unmatched[] = $file;
            }
            foreach ($fileCats as $cat) {
                $matched[$cat] = true;
            }
        }

        $matchedCategories = array_keys($matched);

        // Related closure (one level of transitive `related`).
        $related = [];
        foreach ($matchedCategories as $cat) {
            foreach ((array) ($matrix[$cat]['related'] ?? []) as $rel) {
                if (! isset($matched[$rel])) {
                    $related[$rel] = true;
                }
            }
        }
        $relatedCategories = array_keys($related);

        $allCategories = array_values(array_unique(array_merge($matchedCategories, $relatedCategories)));
        sort($allCategories);

        // Focused filters + test paths + ci jobs.
        $filters = [];
        $paths = [];
        $ciJobs = [];
        foreach ($allCategories as $cat) {
            foreach ((array) ($matrix[$cat]['filters'] ?? []) as $f) {
                $filters[$f] = true;
            }
            foreach ((array) ($matrix[$cat]['tests'] ?? []) as $p) {
                $paths[$p] = true;
            }
            foreach ((array) ($matrix[$cat]['ci_jobs'] ?? []) as $j) {
                $ciJobs[$j] = true;
            }
        }

        // Escalation.
        $escalate = false;
        $reasons = [];

        if (! $diffResolved) {
            $escalate = true;
            $reasons[] = 'change set could not be resolved (unknown/high-risk)';
        }
        if ($unmatched !== []) {
            $escalate = true;
            $reasons[] = count($unmatched).' changed file(s) matched no category';
        }
        foreach ($allCategories as $cat) {
            $catEscalates = (bool) ($matrix[$cat]['escalate_full_suite'] ?? false) || in_array($cat, $fullEscalationCats, true);
            if ($catEscalates) {
                $escalate = true;
                $reasons[] = "category '{$cat}' forces full required suite";
            }
        }
        if ($manifest !== null && in_array('full_required', $manifest->testProfiles(), true)) {
            $escalate = true;
            $reasons[] = "manifest test_profiles includes 'full_required'";
        }

        if ($escalate) {
            $ciJobs['critical_test_gate'] = true;
            $ciJobs['full_suite_gate'] = true;
        } else {
            $ciJobs['critical_test_gate'] = true;
        }

        $filterList = array_keys($filters);
        sort($filterList);
        $pathList = array_keys($paths);
        sort($pathList);
        $ciJobList = array_keys($ciJobs);
        sort($ciJobList);
        $reasons = array_values(array_unique($reasons));

        return [
            'changed_files' => array_values($changedFiles),
            'matched_categories' => $matchedCategories,
            'related_categories' => $relatedCategories,
            'all_categories' => $allCategories,
            'unmatched_files' => array_values($unmatched),
            'focused_filters' => $filterList,
            'test_paths' => $pathList,
            'ci_jobs' => $ciJobList,
            'escalate_full_suite' => $escalate,
            'escalation_reasons' => $reasons,
            'deterministic' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $matrix
     * @return list<string>
     */
    private function categoriesForFile(string $file, array $matrix): array
    {
        $cats = [];
        foreach ($matrix as $category => $def) {
            foreach ((array) ($def['path_globs'] ?? []) as $glob) {
                if ($this->matches($file, $glob)) {
                    $cats[] = $category;
                    break;
                }
            }
        }

        return $cats;
    }

    private function matches(string $file, string $glob): bool
    {
        // Collapse `**` to `*`. Default fnmatch() (no FNM_PATHNAME) lets `*`
        // cross directory separators, so `app/Modules/Inventory/*` matches
        // nested files like `app/Modules/Inventory/Services/Foo.php`.
        $normalised = str_replace('**', '*', $glob);

        return fnmatch($normalised, $file, FNM_NOESCAPE);
    }
}
