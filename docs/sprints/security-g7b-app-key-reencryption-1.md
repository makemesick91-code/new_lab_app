# SECURITY-G7B-APP-KEY-REENCRYPTION-1

**Status:** IMPLEMENTED AND TESTED IN ISOLATION. Not merged, not deployed. The production
key rotation is a separate supervised operation (§6) and is **not** claimed here.

**Type:** `SECURITY_FIX`. **Base:** `19a2ed1c`
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

## 7. Residual risks

- Dumps taken before rotation decrypt only with the old key; restoring one needs the old key
  configured as a previous key and the command run with `--apply`.
- 21 environment copies on the host carry the old key until they are removed under a retention
  decision: 10 in the application root, 1 under `storage/backups`, 10 `.bak` files in the private
  backup tree (measured 2026-10-27). None is readable by another local account.
- The three HMAC users fail closed: an in-flight login, pairing or payment form at the moment
  of rotation has to be redone.
