# CICD-COMPOSER-CACHE-RELEASE-GATES-1

**Status:** implemented and verified locally; not merged.
**Type:** `INFRA_RELEASE`. **Base:** `f9134aac`. Post-audit roadmap, priority 4 (CI/CD
optimisation); item 6 of the audit's CI/CD optimisation plan. CI configuration only; no
application code, nothing to deploy.

## 1. Finding

`.github/workflows/foundation-evidence-gates.yml` has seven jobs that install Composer
dependencies:

- Three GitHub-hosted jobs restore Composer's download cache, keyed on `composer.lock`: quality,
  critical, selective module.
- Three GitHub-hosted jobs do not, and download every package from Packagist on every run:
  NSF-9 release safety, NSF-10 release evidence, and the Full Suite.
- The self-hosted runner keeps its own persistent cache by design.

Measured on the base-branch push run `40491341757` (step times from the GitHub API):

| Job | Composer install | Cache restore / save |
|---|---:|---:|
| Critical, selective, quality (cached) | 3 s | 1–2 s / 0–2 s |
| NSF-9 (uncached) | 9 s | — |
| NSF-10 (uncached) | 8 s | — |

## 2. Change

The same two steps the cached jobs already use, placed before `composer install` in the three
uncached jobs:

- read Composer's download directory;
- `actions/cache@v4`, keyed on the hash of `composer.lock`.

## 3. Expected effect — stated honestly

- Time: about 4 s net per job, so about 8 s per pull-request run, where the Full Suite is skipped.
  That is well under 0.1% of a run whose critical gate takes about 6,000 s. Time is not the reason
  for this change.
- Resilience: the three gates no longer fail when Packagist is slow or unreachable, provided a
  cache for the same `composer.lock` exists.
- Consistency: every hosted job now installs dependencies the same way.

What cannot change:

- the installed versions. The cache holds downloaded archives only, and `composer install` still
  resolves exactly from `composer.lock`;
- the gates themselves, their filters, which tests run, and which checks are required.

The real CI time saving in priority 4 is `CICD-GOVERNANCE-EVALUATION-MEMO-1`.

## 4. Verification

`tests/Feature/Cicd/ComposerCacheCoverageTest.php` (3) is declared mandatory and selected by the
`Cicd` token. It asserts that:

- every hosted job restores the cache before installing, with the cache path being Composer's
  download directory and the key tied to `composer.lock`;
- no cache anywhere contains `vendor/`;
- the self-hosted job uses no `actions/cache`.

Mutation controls, all four detected (workflow restored byte-identical afterwards):

| Mutation | Result |
|---|---|
| base workflow (three jobs uncached) | 1 failed |
| NSF-10 key not tied to `composer.lock` | 1 failed |
| Full Suite caching `vendor/` | 2 failed |
| `actions/cache` on the self-hosted job | 1 failed |

## 5. Rollback

`git revert`.
