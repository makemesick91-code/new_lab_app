# SECURITY-G7B-APP-KEY-REENCRYPTION-1

**Status:** IMPLEMENTED AND TESTED IN ISOLATION. Not merged, not deployed. The production
key rotation is a separate supervised operation (§6) and is **not** claimed here.

**Type:** `SECURITY_FIX`. **Base:** written on `19a2ed1c`; the canonical base `f9134aac`
(SECURITY-F8 / F8B / F8C, the deployed production authority) was merged in on 2026-10-27 —
three additive conflicts, both sides kept; no G7-B application file changed in the merge.
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`).
**Canonical rule:** `.cursor/rules/210-application-key-rotation-and-reencryption.mdc`.

No migration, no route, no permission, no seeder, no feature flag, no frontend asset. One
new configuration file, one service, one Artisan command.

## 1. Finding

The full-system audit of 2026-10-27 found the application key in more places than its
custody allows: environment backup copies on the host and an old application directory
carried the live key. Containing those copies stops further reads; it does not undo the
reads that were possible before. The key has to be rotated.

Laravel can rotate a key without breaking existing data: it decrypts with the current key
and then each key listed as a previous key. That keeps old rows readable only while the
old key stays configured — so a rotation that stops there leaves the old key live, and the
exposure unchanged. Nothing in the application could move rows from the old key to the new
one, and nothing could tell an operator whether the old key was still needed.

## 2. What depends on the key

Measured from the source, not from memory. The audit's earlier count ("6 encrypted columns
in 4 models") was wrong: one of those "encryption" references describes PDF metadata, not
application encryption.

| Kind | Where | Behaviour on rotation |
|---|---|---|
| Encrypted cast | `trx_satusehat_candidates.eligibility_snapshot` | readable through the previous key; rewritten by the command |
| Encrypted cast | `trx_satusehat_candidates.preview_snapshot` | same |
| Encrypted cast | `trx_patient_merge_cases.identity_snapshot_encrypted` | same |
| Encrypted cast | `trx_patient_merge_field_resolutions.final_value_encrypted` | same |
| HMAC | `DoctorAppLoginService` — login ticket (60 s), challenge (120 s) | current key only: an in-flight login retries |
| HMAC | `DoctorDeviceEnrollmentService` — pairing code (15 min) | current key only: an unexpired pairing code is re-issued |
| HMAC | `RmePaymentIntent` — payment form intent | current key only: an open form is refused and reopened, never paid twice |
| Framework | encrypted cookies, signed URLs | verified against current and previous keys; cookies re-encrypted on the next response |

There is no direct `Crypt` call in application code and no encrypted queued job.

Production counts (read-only, 2026-10-27): 8 SATUSEHAT eligibility snapshots encrypted, 0
preview snapshots, 0 merge cases, 0 field resolutions; 1 pending device enrolment, created
2026-09-07 and already expired; 0 password reset tokens.

## 3. Change

- `config/app_key_rotation.php` — the four encrypted columns, the inspection chunk size, and
  `current_key_only_consumers`: the three files that key an HMAC on the current key, each with
  its rotation behaviour.
- `App\Support\Security\AppKeyReencryptionService` — classifies every value as `current`,
  `previous` or `undecryptable`, and rewrites `previous` values one row per transaction
  under a row lock, verifying each rewrite against the current key alone. Writes go through
  the query builder, so `updated_at` and observers do not move. Any undecryptable value
  stops the run before a single write.
- `php artisan security:app-key-reencrypt [--apply] [--json]` — dry run by default; counts
  only; exit 1 on an undecryptable value or a failed rewrite. With `--apply` it records
  `APP_KEY_VALUES_REENCRYPTED` (counts only). `previous_keys_removable: true` is the
  condition for retiring the previous key.

## 4. Tests

`tests/Feature/Security/AppKeyReencryptionTest.php` (8). Rows are written under key A in the
test process; the configuration moves to key B with A as the previous key; the command must
report, rewrite and verify.

1. every `encrypted` model cast under `app/**/Models/` is in the registry;
2. a dry run with nothing to do writes nothing;
3. after rotation old rows stay readable, `--apply` rewrites all four, the previous key is
   then removable, every value decrypts with key B alone, plaintext is identical,
   `updated_at` is unchanged, and the audit row holds counts only;
4. a second `--apply` rewrites nothing and changes no byte;
5. one foreign ciphertext makes `--apply` refuse and write nothing;
6. classification and rewrite across several chunks;
7. no key, plaintext or ciphertext prefix appears in JSON or text output;
8. a payment form signed under the previous key is refused after rotation.

`tests/Feature/Security/AppKeyReencryptionCurrentKeyConsumersTest.php` (4):

1. every file under `app/` that reads the application key is registered, and every registered
   file exists and states its rotation behaviour;
2. a doctor login ticket issued under key A is refused after rotation to B (A still a previous
   key): `unknown_ticket`, a validation error, not a 500, and the ticket stays unspent;
3. a ticket issued after rotation resolves;
4. a pairing code issued under A no longer resolves after rotation; one issued after does.

`tests/Feature/Security/AppKeyReencryptionFrameworkDependenciesTest.php` (4) — what the retire step
does to values the database command cannot rewrite:

1. a cookie encrypted under key A is read normally while A is a previous key, and reads as absent
   (no exception) once A is retired;
2. EncryptCookies re-encrypts such a cookie with the current key on the next response, and the
   reissued value survives retirement — an active browser moves to the new key by itself;
3. a signed link (the email verification route) made with A is valid while A is a previous key and
   invalid once A is retired; a link made after rotation is unaffected;
4. new cookies and links are made with the current key only.

`tests/Feature/Security/AppKeyReencryptionRecoveryTest.php` (6) — the recovery paths, each
end state proved through the application and against a single-key encrypter:

1. once re-encryption is complete every value reads with **no previous key configured**: the dry
   run reports 0 previous keys, 0 previous values, 0 undecryptable, and no value decrypts with
   the old key alone;
2. eleven SATUSEHAT candidates across chunks of 3 — Unicode, nested arrays, `null` flags, large
   Bundles, three `null` preview snapshots — migrate with identical plaintext; a `NULL` snapshot
   stays SQL `NULL`;
3. a **backup taken before rotation and restored after the old key was retired** is refused:
   `undecryptable = 4`, exit 1, not a byte written; with the escrowed old key configured as a
   previous key it reads, is re-encrypted, and reads again with the new key alone;
4. **rollback after a complete re-encryption**: old key current, new key previous, every value
   moved back, the abandoned key retired, nothing decrypts with it;
5. **rollback or resume after an interrupted re-encryption** (2 of 4 values rewritten): every
   value readable in the mixed state; rollback moves exactly the 2 back; resuming forward
   rewrites exactly the other 2;
6. a rollback that drops the new key (old key current, no previous key) is refused and writes
   nothing.

Mutations of the service, each restored by copy: classifying "current" with every key → 6
fail; never rewriting previous values → 6 fail; leaving the rewrite under the old key → 6 fail.

Consequence for step 7 of §6: retiring the previous key signs out a browser not seen since the
rotation and expires older verification links. Nothing returns a 500.

Mutations, each restored by copy: an unregistered file reading the key → 1 fails; the ticket
HMAC no longer depending on the key → 2 fail; the same for the pairing code → 2 fail.

Green on SQLite and on PostgreSQL 16 (16.15). Declared in
`config/ci_runner.php` `critical_gate_mandatory_suites`, selected by the `AppKeyReencryption`
token in both critical-gate variants (verified with `pest --list-tests`: 8 cases each).

## 5. Rehearsal of the production procedure

The rotation script was rehearsed end to end against a throwaway PostgreSQL 16.15 container
migrated with this branch's schema and seeded with 8 encrypted rows, using a fixture
environment file and a shim that runs the real command. Results:

| Path | Result |
|---|---|
| plan | GO, 8 current, nothing changed |
| apply | new key active, old key previous, 8 rewritten, 0 values on the previous key |
| verify | 1 previous key configured, 0 values on it, 0 undecryptable |
| retire-previous, verify, finalize | previous key removed; 8 values read through the model with the new key alone |
| apply → rollback | the original key is restored (proved by salted hash), values moved back, abandoned key then retired |
| rollback twice / after retirement | refused |
| foreign ciphertext present | apply refused before touching anything; environment file byte-identical; no state directory |
| environment file mode wrong | refused |
| finalize before retirement | refused |

The environment file kept its inode and mode through every step. No key material appeared
in any log. The fixture file and the container were destroyed after the rehearsal.

### 5.1 Backup recovery rehearsal (2026-10-27, after the base merge)

A second throwaway PostgreSQL 16.15 container, keys held only in process memory, synthetic
rows only; the dump, the databases and the container were destroyed afterwards.

| Step | Result |
|---|---|
| migrate, seed 4 encrypted values under key A | done |
| `pg_dump` (16, plain SQL, as production) **before** rotation | 797 KB; synthetic plaintext occurrences in the dump: 0 |
| rotate: B current, A previous, `--apply` | GO, 4 rewritten, removable |
| retire A (B alone) | GO, 0 previous keys, 4 current; application read identical |
| restore the pre-rotation dump into a fresh database | done |
| B alone on the restored database, `--apply` | NO-GO, `undecryptable = 4`, exit 1; rows byte-identical (md5); application read fails with `DecryptException` |
| escrowed A configured as previous | application read identical; `--apply` GO, 4 rewritten |
| retire A again on the restored database | GO, 0 previous keys; application read identical |

Conclusion carried into §6: the old key must stay in sealed custody for at least the
retention window of pre-rotation dumps, because without it such a dump is unrecoverable.

## 6. Production rotation — not part of this change

The rotation itself is a supervised operation in a quiet window, after this change is
deployed:

0. SECURITY-F8B deployed: the rotation rebuilds the configuration cache, which carries the key in
   plain text, and that cache must not be readable by another local account;
1. preflight: `security:app-key-reencrypt --json` reports 0 undecryptable and 0 previous;
   `session.encrypt` recorded; no in-flight payment form, login or pairing;
2. backup taken and verified;
3. new key written into the environment file in place; old key becomes the previous key;
4. configuration cache rebuilt as the runtime user; queue worker restarted; PHP-FPM reloaded;
5. `security:app-key-reencrypt --apply`; verify 0 values on the previous key;
6. health, login, SATUSEHAT readiness and the cashier flow checked;
7. **later**, after the backup retention window is covered by sealed custody of the old key,
   retire the previous key.

Every other copy of the environment file that still carries the old key as the live key is
part of the exposure being closed and has to be handled before the old key is called
retired.

## 6.1 Production readiness report (owner Decision 4, 2026-10-28)

Owner decision 4 (2026-10-28): this pull request may merge once the required checks pass. The
production key rotation stays **NO-GO** until this checklist is complete and the owner gives a
separate execution approval. Merging deploys nothing.

| # | Prerequisite | Status | Evidence, or what remains |
|---|---|---|---|
| 1 | Secure legacy-key escrow | **NOT ESTABLISHED** — owner action | The old key must sit in sealed custody, separate from the host, before rotation. The rotation tool's state directory holds both keys only until `finalize`, so it is not escrow. Needed: named custodians and a medium, as for Android signing custody (several custodians, encrypted copies, a restore drill). The key value is never printed, sent over chat, or stored in the repository, CI or the knowledge base. |
| 2 | Pre-rotation backup recovery | **Mechanism verified; in-window step defined** | §5.1: a dump taken before rotation restores only with the escrowed old key configured as a previous key, then `--apply` moves its values to the new key. Production restore drill 2026-10-28 05:35 WITA (`scripts/restore-rehearsal.sh`): fresh snapshot verified 9/9, 174 tables restored into a scratch database in 7 s, scratch database dropped, production untouched. In the window, the backup taken at step 2 of §6 is the one that must be restorable. |
| 3 | Encrypted data migration | **Verified in isolation; production dry run needs deploy** | §5: 8 values rewritten and read with the new key alone. Production scope measured read-only on 2026-10-28: 8 SATUSEHAT eligibility snapshots, 0 preview snapshots, 0 merge cases, 0 field resolutions (unchanged since 2026-10-27). `security:app-key-reencrypt --json` (dry run, counts only) can run on production only after this code is deployed, which needs its own approval. |
| 4 | Recovery with the old key removed from active configuration | **Verified in isolation** | §5 `retire-previous`, and §5.1 on a restored dump. In production this is step 7 of §6, run only after escrow (item 1) covers the backup retention window. |
| 5 | Doctor authentication and device pairing | **Verified in isolation; in-window smoke defined** | `AppKeyReencryptionCurrentKeyConsumersTest`: login tickets (60 s) and challenges (120 s) fail closed and the doctor retries; pairing codes (15 min) are re-issued. In the window: one doctor login and one device verification after step 4. |
| 6 | Payment-intent invalidation | **Verified in isolation** | An open payment form is refused and reopened, never paid twice (payment idempotency rule). In the window: no cashier form open at the moment of rotation (item 7). |
| 7 | No active clinical or cashier transactions | **In-window gate** | Run read-only immediately before step 3 of §6: no visit in progress, no payment form open, no active doctor or cashier session, queue empty. Measured at 05:45 WITA as a reference only: 2 session rows, 0 queued jobs. |
| 8 | Queue and worker readiness | **Verified now; restart is in-window** | Worker, PHP-FPM and nginx active; 0 queued jobs; 0 failed jobs; no encrypted queued job exists in the code. Step 4 of §6 restarts the worker after the configuration cache is rebuilt. |
| 9 | Production rollback | **Rehearsed; tool not yet installed** | §5: `apply` then `rollback` restores the original key (proved by salted hash) and moves values back. The tool (`g7b-rotate-app-key.sh`: plan, apply, verify, rollback, retire-previous, finalize) goes into the root-only tools directory on the host when execution is prepared, as for G7-A. |
| 10 | Final readiness report | This section | Remaining before an execution approval can be requested: item 1 (escrow), deployment of this change (separate approval), the production dry run (item 3), and a retention decision for the 21 environment copies that still carry the old key as the live key (§7). |

Production configuration is unchanged by this report: no key was read, printed or rotated, and
the only production write was the scratch database the restore drill creates and drops.

## 7. Residual risks

- Dumps taken before rotation decrypt only with the old key; restoring one needs the old key
  configured as a previous key and the command run with `--apply`.
- 21 environment copies on the host carry the old key until they are removed under a retention
  decision: 10 in the application root, 1 under `storage/backups`, 10 `.bak` files in the private
  backup tree (measured 2026-10-27). None is readable by another local account.
- The three HMAC users fail closed: an in-flight login, pairing or payment form at the moment
  of rotation has to be redone.
