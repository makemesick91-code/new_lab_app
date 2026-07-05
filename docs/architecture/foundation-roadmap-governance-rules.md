# Foundation Roadmap Governance Rules (ROADMAP-R001..R010)

Published by `App\Services\Foundation\FoundationRoadmapGovernanceService` into
`architecture:foundation-governance-summary` as the `roadmap_governance`
section. Informational — never wired into the blocking `combinedDecision`
(mirrors how `storage_governance`, `stateless_governance`, `lb_governance`,
`database_replica_governance`, `cache_redis_governance`,
`observability_governance`, and `observability_pipeline_governance` are all
surfaced without themselves gating the combined GO/NO-GO).

| Rule | Title | Description |
|---|---|---|
| ROADMAP-R001 | Every GO-tagged, deployed foundation sprint is in the canonical roadmap | Any foundation sprint that has been GO-tagged and deployed must have a corresponding entry in `config/foundation_roadmap.php`. |
| ROADMAP-R002 | `next_recommended_sprint` never points at a completed sprint | The computed `next_recommended_sprint` must never resolve to a sprint whose own status is already `completed`. |
| ROADMAP-R003 | Completed entries carry status, title and GO evidence | Every roadmap entry that opts into `governance_section` tracking and is marked `completed` must define a non-empty `go_tag` (or equivalent deploy evidence reference). |
| ROADMAP-R004 | Similarly named/duplicate sprints must be disambiguated | Sprints that share a short name with an earlier, distinct sprint (e.g. `CACHE-1` vs the later Redis-readiness `CACHE-1-REDIS-READINESS`) must carry a `disambiguation_note` so governance never conflates them. |
| ROADMAP-R005 | New rule-adding sprints register a governance section | Any foundation sprint that adds new governance rules must register its own section in the foundation governance summary. |
| ROADMAP-R006 | Roadmap check command stays non-destructive | The roadmap check command must be safe to run in CI/VPS: no mutation, no network call, no GitHub API dependency. |
| ROADMAP-R007 | Roadmap is not the sole source of truth | The roadmap config documents intended sequencing; it must never override what code, migrations, routes, and tests actually prove. |
| ROADMAP-R008 | Roadmap updates stay governance/config/docs only | Updating the roadmap must never change a runtime driver, production data, security policy, or business workflow by itself. |
| ROADMAP-R009 | Deploy evidence records GO tag, commit, backup, smoke and rollback | Every foundation sprint entry represents a deploy that must have recorded its GO tag, commit, DB backup, smoke result, and rollback note in its evidence doc. |
| ROADMAP-R010 | Canonicalization preserves all sibling foundation governance GO | Canonicalizing the roadmap must never regress STORAGE/STATELESS/LB/REPLICA/CACHE/OBS-1/OBS-2 governance sections away from GO. |

## Reading the `roadmap_governance` summary section

```json
{
  "roadmap_governance": {
    "decision": "GO",
    "next_recommended_sprint": "MON-1",
    "stale_next_detected": false,
    "completed_count": 15,
    "missing_metadata": [],
    "total_planned_sprints": 20,
    "rules": [ { "id": "ROADMAP-R001", "title": "...", "description": "..." }, ... ],
    "command": "foundation:roadmap-check"
  }
}
```

- `decision` flips to `WATCH` if `stale_next_detected` is true, if
  `missing_metadata` is non-empty, or if the underlying
  `architecture:foundation-roadmap-check` decision is not `GO`.
- `missing_metadata` lists sprint ids that are `completed`, opted into
  `governance_section` tracking, but missing a `go_tag` — this only applies
  to sprints that carry a `governance_section` key; legacy pre-STORAGE-1
  entries (`NSF-9`, `NSF-10`, `CACHE-1`, `QUEUE-1`, `DBPERF-1`, `DBPERF-2`,
  `RPT-1`) were not retrofitted with this metadata and are intentionally
  excluded from the check.

## Relationship to the existing `roadmap` section

`FoundationGovernanceSummaryService` already published a `roadmap` section
backed by `App\Services\Architecture\FoundationRoadmapService` (the original
source-lock validator, `architecture:foundation-roadmap-check`) before this
sprint. That section, its command, and its tests are untouched.
`roadmap_governance` is an additive sibling section for the ROADMAP-R001..R010
rules, following the same separation pattern already used for
`cache_governance`/`cache_redis_governance` and
`observability_governance`/`observability_pipeline_governance`.

## Handling duplicate/ambiguous sprint ids (e.g. old vs new CACHE-1)

1. Never reuse an existing sprint id for an unrelated later sprint.
2. If a later sprint's natural short name collides with an earlier one, pick
   a disambiguated id (e.g. `CACHE-1-REDIS-READINESS`) rather than the bare
   collided name.
3. Add `disambiguates` (the id it could be confused with) and
   `disambiguation_note` (a one-line explanation of which is which) to both
   entries.
4. Never let `RC-1`/`NDA-1`-style aggregate `depends_on` lists silently
   collapse the two — reference the disambiguated id explicitly.

## Next recommended sprint after ROADMAP-1

**`MON-1` — Health Monitoring, Alerting & Uptime Readiness** (priority 16,
`config/foundation_roadmap.php`).

## Privacy/security note

Roadmap governance rules and the `roadmap_governance` summary section never
contain PII or secrets — only sprint ids/titles/statuses, public git tag and
commit references, and command names.
