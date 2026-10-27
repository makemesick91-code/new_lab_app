# SECURITY-F8B-CONFIG-CACHE-PRIVATE-1

**Type:** security fix (deploy) · **Base:** `043a4ae2` (SECURITY-F8-PRIVATE-STORAGE-PATHS-1, deployed)
**No migration, no route, no permission, no application code change.**

## 1. Finding

`php artisan config:cache` writes the resolved configuration to
`bootstrap/cache/config.php`. That file holds every value the environment file
supplies — the database password and the application key among them.

Since INFRA-SEC-ENV-1 the environment file is `0640 root:<runtime group>`, so no
other local account can read it. The deploy, however, normalized
`bootstrap/cache` to `2775` with files `0664`, like any runtime-writable
directory. The application and its parent directories are `0755`, so every local
account on the host could read both secrets from the cache.

Measured on the production host after the SECURITY-F8 deploy (read-only, values
never printed):

| Fact | Value |
|---|---|
| `bootstrap/cache` / `config.php` | `2775` / `0664` |
| Database password present in `config.php` | yes |
| Application key present in `config.php` | yes |
| Readable by `www-data`, `postgres`, `nobody` | yes, all three |
| Other files holding either secret and readable by another account | none |

The host was contained by hand the same day (`chmod o-rwx` on the directory and
its files, prior modes recorded for rollback). Without this change the next
deploy would widen it again.

The isolation gate (`verify-runtime-isolation.sh`) never looked at the cache:
it checks the environment file and the declared private paths, and
`bootstrap/cache` was not declared. That is why every deploy reported isolation
GO while the secrets were readable.

## 2. Change

- `deploy/runtime-identity.conf` — `bootstrap/cache` added to `DMS_PRIVATE_PATHS`.
- `scripts/lib/private-paths.sh` — the validator admits `bootstrap/cache` by
  exact name, in addition to paths under `storage/`. `bootstrap`,
  `bootstrap/app.php`, anything below the cache directory and traversal forms
  stay invalid.

Nothing else changes. The existing SECURITY-F8 machinery does the work:

- the deploy and rollback prune declared paths from the 2775/0664 widening and
  strip "other" from them, giving the runtime owner read/write;
- the deploy merges the target release's declaration after the fast-forward, so
  the first deploy that ships this change already protects the cache;
- the isolation gate checks every declared path and fails when another account
  can read or traverse it.

After the change the cache is `2770` with files `0660` after every deploy.
A file written later by the runtime (for example by a second `config:cache`) is
unreachable for other accounts because the directory has no "other" bits.

Consequence: a deploy of a release older than this change, against a host that
already declares `bootstrap/cache`, fails closed — the older validator rejects
the path. A union of private paths may only add protection.

## 3. Tests

In the existing SECURITY-F8 suites (both selected by the `RuntimeIdentityIsolation`
token in both critical-gate variants; both declared in
`critical_gate_mandatory_suites`):

- `RuntimeIdentityIsolationPrivatePathsTest`
  - the identity authority declares `bootstrap/cache`, Laravel's cached config
    path lies under it, and application source under `bootstrap/` does not;
  - the validator admits `bootstrap/cache` exactly and rejects `bootstrap`,
    `bootstrap/app.php`, `bootstrap/cache/sub`, `bootstrap/cache/` and
    `storage/../bootstrap/cache`;
  - the deploy that first ships the declaration adopts it before normalizing;
  - the real deploy and rollback normalization, run on a fixture with the host's
    old modes (`2775` / `0664`), leave the cache `2770` / `0660`. The previous
    expectation in this suite (`config.php` widened to `0664`) encoded the
    defect and was changed.
- `RuntimeIdentityIsolationTest` — the isolation gate is NOT GO when the
  declared cache is world-readable and GO once it is private.

Evidence: 58 passed. Against the unfixed source 4 of the new cases fail.
Mutations, each restored by copy: validator widened to any `bootstrap/` path →
2 fail; deploy restrict step skipping non-`storage/` paths → 1 fails; rollback
restrict step skipping them → 1 fails.

## 4. Residual

- `storage/framework/cache` (the file cache store) is still widened. Today it
  holds only the permission cache. If a feature ever caches a credential there
  (an OAuth token, for example), declare that directory private first.
- Environment copies whose names do not start with `.env.bak`, `.env.backup`,
  `.env.save`, `.env.old` or `.env.orig` (for example `.env.wave1-backup-…`) are
  classified as runtime files by `harden-secret-permissions.sh` and set to
  `0640` on every deploy. No other account can read them, but they are not
  `0600`. Tracked separately.
