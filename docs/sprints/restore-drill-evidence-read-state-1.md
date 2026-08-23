# RESTORE-DRILL-EVIDENCE-READ-STATE-1

**Status:** implemented, merged, deployed, GO.
**Base authority:** `5478d72f8058f39914bcc8af0220d8935068a0f2` (`ci-monitoring-critical-token-coverage-1-go`).
**Full Suite:** `FULL_SUITE_EXECUTION_COUNT=0` — `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` (Rule 107 still ACTIVE).

---

## 1. What was wrong

Restore-drill evidence is operational safety evidence: the ROLL-5 rollout gate
will not clear Stage 1 without it. `RestoreDrillEvidenceService` read that
evidence like this:

```php
$raw = (string) @file_get_contents($found);   // ← the defect
...
$data = json_decode($raw, true);
if (! is_array($data)) {
    return $this->fail('bukti uji restore tidak valid: JSON tidak dapat diurai', ...);
}
```

`file_get_contents()` returns `false` when the read fails. Casting `false` to a
string yields `''`, and `''` is indistinguishable from a file that genuinely
contained nothing. By the time the decoder saw it, the only surviving fact was
"this did not parse" — so **every upstream fault was reported to the operator as
malformed JSON**, including faults where not a single byte was ever read.

There was no `is_readable()` check anywhere in the path, and `locateEvidence()`
skipped any candidate with `filesize() === 0`, so a zero-byte file was reported
as *no file at all*.

### The safety property was never broken

Every one of these states was — and remains — non-GO. **No false GO existed and
none was introduced.** This is a state-integrity and observability defect, not a
false-green defect. That distinction is why the fix is allowed to be small: the
verdicts were already right, so the work is to make the *reasons* true without
letting any verdict move.

### It was already known, and had been accepted

`tests/Feature/Cicd/CriticalGateWarningContractTest.php` carried this note:

> *RestoreDrillEvidenceService — casts a failed read to a string, so an
> unreadable evidence file reports "invalid JSON" rather than "unreadable". It
> still FAILS CLOSED …, so the decision is correct and only the reason is less
> specific.*

The fail-closed half of that judgement was correct and was re-measured at the
base commit. The half that does not hold up is the conclusion that a less
specific reason is therefore harmless: it sends an operator to fix a document's
format when the actual fault is a permission or an I/O failure. That reasoning is
superseded in place, in the file that carried it.

---

## 2. Reproduction — measured, not assumed

Twelve fixtures were run against the **unmodified base code** at `5478d72`, as
uid 1000 (not root, so `chmod 000` genuinely denies the read):

| Fixture | Base outcome | Truthful? |
|---|---|---|
| absent | `WATCH` "belum ada bukti" | correct |
| **zero-byte file present** | `WATCH` **"belum ada bukti"** | **wrong — the file exists** |
| whitespace only | `FAIL` `invalid_json` | correct |
| malformed JSON | `FAIL` `invalid_json` | correct |
| **`12345` (valid JSON)** | `FAIL` **"JSON tidak dapat diurai"** | **wrong — it parsed fine** |
| **`null` (valid JSON)** | `FAIL` **"JSON tidak dapat diurai"** | **wrong — it parsed fine** |
| missing schema field | `FAIL` `missing_restore_target` | correct |
| unfaithful timestamp | `WATCH` `evidence_timestamp_unparseable` | correct |
| stale | `WATCH` `evidence_stale` | correct |
| valid + fresh | `GO` | correct |
| **unreadable (`chmod 000`)** | `FAIL` **"JSON tidak dapat diurai"** | **wrong — no byte was read** |
| directory at the path | `WATCH` absent | correct |

`CONFLATED_STATES` = `EMPTY → ABSENT`, `UNREADABLE → INVALID_JSON`,
`READ_FAILED → INVALID_JSON`, `VALID_JSON_NOT_OBJECT → INVALID_JSON`.

---

## 3. The fix

**`App\Support\Foundation\RestoreDrillEvidenceReader`** (new) decides the read
outcome once, before any content is interpreted, and never coerces `false`:

```
! is_file            → READ_ABSENT      contents: null
! is_readable        → READ_UNREADABLE  contents: null
read returned false  → READ_FAILED      contents: null
read returned ''     → READ_EMPTY       contents: ''
otherwise            → READ_OK          contents: <bytes>
```

`contents` is non-null only for `READ_EMPTY` and `READ_OK`, so a caller cannot
mistake *read nothing* for *read an empty document*. Error suppression on the
read is noise control (the Critical Gate warning baseline is a declared `0`); the
control flow is the explicit `=== false` comparison, never the presence of a
warning.

The decode stage is split by `json_last_error()`: a decoder rejection stays
`invalid_json`; bytes the decoder accepted that are simply not an evidence object
become `evidence_not_an_object`. Both remain `FAIL`.

### Candidate selection is deliberately unchanged

`locateEvidence()` keeps its exact `is_file($abs) && filesize($abs) > 0`
predicate. This is the load-bearing decision of the whole sprint. Making a read
fault *fall through* to the next candidate would have been strictly **more
permissive** — a secondary path would answer for a canonical file nobody could
read. An existing-but-unreadable candidate therefore still blocks, exactly as
before. The zero-byte case is instead detected separately (`firstEmptyCandidate`)
only when no candidate was usable, so the reason improves while the choice of
file does not move.

---

## 4. State matrix

Thirteen states, each carrying a distinct operational meaning. Per the
visualization form heuristic (>~7 meaningful classes → a table, not more colors),
this is a table; no chart form and no color assignment applies.

| Physical / content state | Read state | Parse state | Trust | Freshness | Readiness | Operator reason |
|---|---|---|---|---|---|---|
| no file | `absent` | n/a | untrusted | n/a | **WATCH** | `evidence_absent` |
| file exists, 0 bytes | `empty` | n/a | untrusted | n/a | **WATCH** | `evidence_empty` |
| file exists, not readable | `unreadable` | n/a | untrusted | n/a | **FAIL** | `evidence_unreadable` |
| read returned false (I/O, TOCTOU) | `read_failed` | n/a | untrusted | n/a | **FAIL** | `evidence_read_failed` |
| unknown read state | `<state>` | n/a | untrusted | n/a | **FAIL** | `evidence_read_failed` |
| whitespace only | `ok` | rejected | untrusted | n/a | **FAIL** | `invalid_json` |
| malformed JSON | `ok` | rejected | untrusted | n/a | **FAIL** | `invalid_json` |
| valid JSON, not an object | `ok` | accepted | untrusted | n/a | **FAIL** | `evidence_not_an_object` |
| valid JSON, schema incomplete | `ok` | accepted | untrusted | n/a | **FAIL** | `missing_<field>` |
| secret / KTP / NIK detected | `ok` | n/a | **unsafe** | n/a | **FAIL** | `leaked_<pattern>` |
| production overwrite / prod env | `ok` | accepted | **unsafe** | n/a | **FAIL** | `production_*` |
| valid, unfaithful timestamp | `ok` | accepted | trusted doc, untrusted instant | **unageable** | **WATCH** | `evidence_timestamp_*` |
| valid, trusted, age > 720h | `ok` | accepted | trusted | stale | **WATCH** | `evidence_stale` |
| valid, trusted, age ≤ 720h | `ok` | accepted | trusted | fresh | **GO** | — |

Freshness is computed only from a trusted instant. Untrusted evidence is never
assigned an age, so it can never satisfy the freshness requirement by accident.

---

## 5. Readiness differential

Measured across all 13 fixtures, base behaviour vs. fixed behaviour:

```
READINESS_DELTAS = 0
```

Every state keeps the exact verdict it had. Nothing became more permissive;
nothing became stricter. Only the reported reason changed. This is the property
the sprint is judged on — a truthfulness fix that moves a verdict is not a
truthfulness fix.

The one boundary worth stating explicitly, because it is asymmetric on purpose:
**zero bytes = no evidence recorded (WATCH); any bytes that are not valid
evidence = corrupt evidence (FAIL).** Whitespace-only content has bytes, so it is
`FAIL`, exactly as it was at base. Both readings are defensible; the byte-level
line was chosen because it is mechanically unambiguous and because it keeps the
differential at zero.

---

## 6. Verification

- **Mutation control** — reintroducing `(string) @file_get_contents($found)` and
  discarding the read state fails **6** tests in the new suite. The contract is
  bound to the read boundary, not to message text.
- **Real-filesystem control** — `chmod 000` genuinely denies the read as uid
  1000; that test is skipped (never silently passed) when the running user can
  read a `0000` file, and the injected-reader control covers the same state
  unconditionally.
- **Injected reader** — `READ_FAILED` is a genuine `file_get_contents` outcome
  whose *timing* cannot be scheduled by a test, so it is injected. The state is
  real; only its provocation is synthetic.
- **CI selection verified by execution** — the critical filter selects **24**
  tests from `RestoreDrillEvidenceReadStateTest`, confirmed with `--list-tests`
  rather than by reading the token list. The suite is also declared in
  `config/ci_runner.php` `critical_gate_mandatory_suites`, so a rename can move
  its coverage but can no longer delete it.

---

## 7. Durable rules

1. Read state is determined **before** JSON parsing or evidence validation.
2. A failed read is **never** represented as empty content — `false` is not cast
   to a string, and no `?: ''` stands between a read and its classification.
3. `invalid_json` means bytes were read and the decoder rejected them. It is
   never the reason for a filesystem read failure, and never the reason for
   well-formed JSON that is merely the wrong shape.
4. A successfully-read empty file and a failed read are distinct states.
5. Freshness is evaluated only after source, read, decode, schema and timestamp
   trust all succeed. Untrusted evidence is never assigned a trusted age.
6. Absent, empty, unreadable, read-failed, invalid-JSON, not-an-object,
   invalid-schema, unsafe, invalid-timestamp, future-timestamp and stale evidence
   **must never produce readiness GO**.
7. Candidate selection must not become more permissive to improve a reason. A
   read fault on a selected candidate blocks; it does not fall through.
8. Restore-drill read-state changes are verified in production
   **non-destructively**: canonical evidence is never mutated and no live restore
   is performed to exercise a state.
9. Artisan flags are never guessed on production — a failed diagnostic writes a
   real application ERROR entry.
