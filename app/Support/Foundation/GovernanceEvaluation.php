<?php

declare(strict_types=1);

namespace App\Support\Foundation;

use Closure;

/**
 * CICD-GOVERNANCE-EVALUATION-MEMO-1 — one governance evaluation computes each
 * gate once.
 *
 * The enterprise foundation gates re-verify their predecessors: ENT-6 asks
 * ENT-5 for its decision, ENT-7 asks ENT-5 and ENT-6, and so on up to ENT-16.
 * Each gate receives fresh instances of its predecessors, so every level
 * re-ran the whole chain below it. One `architecture:foundation-governance-summary`
 * constructed the ENT-5 gate 2,080 times and took about 110 seconds; every test
 * that renders the summary paid that again.
 *
 * `remember()` caches a result for the lifetime of the OUTERMOST evaluation and
 * forgets everything the moment it returns, so:
 *   - inside one evaluation, configuration and repository state are fixed, and a
 *     gate asked twice gives the same answer it would have computed again;
 *   - two separate evaluations never share a result — a caller that changes
 *     configuration between calls (as tests do) always sees a fresh answer;
 *   - an exception leaves nothing behind.
 *
 * Disable with `foundation_governance.evaluation_memo = false`; the result is
 * then byte-identical, only slower (pinned by GovernanceEvaluationMemoTest).
 */
final class GovernanceEvaluation
{
    private int $depth = 0;

    /** @var array<string, mixed> */
    private array $memo = [];

    private int $hits = 0;

    private int $misses = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $key, Closure $compute): mixed
    {
        if (! $this->enabled()) {
            return $compute();
        }

        $this->depth++;

        try {
            if (array_key_exists($key, $this->memo)) {
                $this->hits++;

                return $this->memo[$key];
            }

            $this->misses++;
            $value = $compute();
            $this->memo[$key] = $value;

            return $value;
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->memo = [];
            }
        }
    }

    /**
     * Run $fn as one evaluation, so every gate it reaches shares one memo.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function within(Closure $fn): mixed
    {
        if (! $this->enabled()) {
            return $fn();
        }

        $this->depth++;

        try {
            return $fn();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->memo = [];
            }
        }
    }

    /** @param array<int, mixed> $args */
    public static function key(string $class, string $method, array $args = []): string
    {
        return $class.'::'.$method.($args === [] ? '' : ':'.hash('sha256', serialize($args)));
    }

    public function enabled(): bool
    {
        return (bool) config('foundation_governance.evaluation_memo', true);
    }

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /** @return array{hits: int, misses: int, cached: int, depth: int} */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'cached' => count($this->memo), 'depth' => $this->depth];
    }
}
