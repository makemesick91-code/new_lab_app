# FIX-RECEIPT-PDF-TEXT-CONTIGUITY-1

**Corrective sprint.** Fixes the pre-existing test-contract defect that reddened the one
authorised consolidated Full Suite.

| | |
|---|---|
| Base authority | `e1675586ae39d4ee9b7d677ac3c90bdde4b75ffb` (`final-stabilization-residual-audit-1-go`) |
| Failed Full Suite | run **32700184849**, job 97362090553 — `1 failed, 1 risky, 5 skipped, 7372 passed` |
| Failed closure candidate | `e7b8dde460f3678f2a97b376e83aea66c168c173` — **evidence only**: unmerged, undeployed, NO-GO |
| Classification | `TEST_CONTRACT_DEFECT` / **pre-existing** / not a candidate regression |
| Runtime behaviour change | **false** — four test files, zero application files |
| Full Suite executions | **0** — not authorised for this sprint |

---

## 1. The failure

`tests/Feature/RME/RmeReceiptOnePageTest.php:215`

```php
expect($text)->toContain($visit->patient->name);
```

where `$text` came from `pdftotext -layout`.

## 2. Root cause — reproduced, not assumed

Reproduced against the clean base authority `e1675586`, byte-identical to the recorded
signature:

```
LINE[07] |Nama Pasien              Miss Marcella O'Conner      No. Rekam Medis     MRN-U8XPPPBS|
LINE[08] |                         DVM|
```

`pdftotext -layout` serialises the **visual** layout. A value that overflows its
fixed-width cell wraps, and the columns beside it are interleaved into the same lines. So a
semantically continuous value is not a contiguous substring of the extraction.

**The receipt is correct.** The full name is rendered, nothing is clipped, nothing is
mis-escaped. The assertion was testing a *layout* property while claiming to test a
*content* property.

### The apostrophe reading is disproven

The faker name `Miss Marcella O'Conner DVM` makes this look like the repository's
documented faker-vs-Blade-escaping flake class. It is not. Controls, same base, same
renderer:

| name | length | apostrophe | old `toContain` |
|---|---:|---|---|
| `Miss Marcella O'Conner DVM` | 26 | yes | **FAIL** |
| `Alexandria Catherine Santoso` | 28 | **no** | **FAIL** |
| `Budi Santoso` | 12 | no | pass |
| `O'Brien` | 7 | yes | pass |

A long name with **no** apostrophe fails identically; a short name **with** one passes.
It is length, not escaping. `e()` would have changed nothing.

The wrap point is a proportional-font pixel width, not a character count — `Alexandria
Catherine` (20 chars) wrapped where `Miss Marcella O'Conner` (22) did not. **No character
threshold is encoded anywhere**; the fixture is pinned by demonstrated behaviour instead.

### Two further layout shapes found by measurement

Measuring rather than assuming turned up two more shapes the naive contract also breaks on:

1. **Table column** — a long treatment description is split *around* its own numeric row,
   so "join the next line" cannot rebuild it:

   ```
   LINE[16] |Perawatan Saluran Akar Gigi Molar Pertama Rahang Bawah|
   LINE[17] |                                 1     Rp 1.250.000     Rp 1.250.000|
   LINE[18] |Kanan Kunjungan Kedua|
   ```

2. **Stacked label** — the RME visit PDF prints the label above the value at full page
   width, so there is no adjacent cell at all. It wraps too, at ~145 characters (the
   `mst_patients.name` column allows 150).

## 3. The corrected contract

`tests/Pest.php` gains column-bounded, wrap-tolerant readers beside the existing PDF
helpers:

| helper | layout shape |
|---|---|
| `pdfLayoutFieldValue($text, $label)` | value **beside** the label, or **stacked beneath** it |
| `pdfLayoutColumnText($text, $from, $to)` | a table column band between two headings |
| `pdfLayoutFullWidthText($text)` | centred header / section lines that own their whole line |
| `pdfNormalizeText($value)` | collapses whitespace runs so a rejoined wrap compares equal |
| `pdfLayoutSegments($line)` | splits one layout line into its column cells |

Reading a value out of **its own column band** is *stricter* than the substring search it
replaces: a neighbouring column is structurally outside the band and can no longer satisfy
the assertion, and the patient name is now compared for **equality**, not containment.

### Explicitly rejected implementations

- **Stripping all whitespace** from the extraction. It fuses `Nama Pasien` with
  `No. Rekam Medis` and creates matches for values that were never printed.
- **Flattening the whole page** into one haystack. Same defect — the recorded evidence
  shows the adjacent column interleaved into the very line under test.
- **Per-token containment.** `Miss`, `Marcella` and `DVM` each appear elsewhere; the
  reordered-token control (`Catherine Alexandria Santoso`) exists to keep that closed.
- **Any change to the receipt.** No Blade, CSS, column width, font size or escaping was
  touched. Fixing a broken test by changing correct production output would have hidden
  the defect rather than removed it.

## 4. Determinism

`receiptVisit()` now pins `receiptWrappingPatientName()` — `Alexandria Catherine Santoso`,
deliberately apostrophe-free — so the wrap is exercised on **every** run instead of the ~6%
of runs where faker happened to produce a long name. The coverage claim is itself asserted:
if the fixture ever stops wrapping, the test says so rather than silently ceasing to cover
the class.

## 5. Test matrix

| case | expectation | result |
|---|---|---|
| short plain `Budi Santoso` | field equals name, does not wrap | pass |
| short apostrophe `O'Brien` | field equals name, does not wrap | pass |
| long plain `Alexandria Catherine Santoso` | field equals name, **wraps** | pass |
| long apostrophe `Miss Marcella O'Conner DVM` | field equals name, **wraps** | pass |
| wrapped treatment description | found in its column band | pass |
| wrong patient (`Bambang Wijaya`) | must not match | fails as required |
| lost wrapped tail (`Alexandria Catherine`) | must not match | fails as required |
| reordered tokens | must not match | fails as required |
| wrong MRN | must not match | fails as required |
| MRN column bleeding into the name field | must not happen | asserted |
| one-page receipt contract | preserved | pass |

## 6. Guard — the third shape

`FullSuiteBaselineContractTest` pinned two shapes, both against a rendered **HTML body**:
`content())->toContain($var)` and unescaped `assertSee(...)`. Neither matches a comparison
against **PDF-extracted text**, which is why this survived. The third shape is now pinned.

Scope is deliberately narrow (`->toContain` is *not* banned wholesale): only files that
extract PDF text, and only the free-text property list the `assertSee` guard already uses
(`name|description|title|address|notes`). Literals, identifiers, formatted money, and
values routed through `pdfNormalizeText()` all stay legal.

Two traps the guard closes in its own implementation:

- **Greedy capture.** Arguments are read with a parenthesis counter, so one safe assertion
  on a line cannot launder an unsafe one beside it.
- **Self-report.** Comments are stripped with the PHP tokenizer. Without this, any file
  that *documents* the forbidden shape reports itself, and the cheapest way to silence the
  guard becomes deleting the explanation.

Running it immediately found a third live instance — the centred clinic-name header — and a
second affected file, `MedicalRecordPrintOdontogramSeparationTest`. Both corrected.

## 7. Mutation controls

| mutation | expected | actual |
|---|---|---|
| restore `toContain($visit->patient->name)` | receipt test AND guard fail | **both failed**, guard named the offender |
| helper drops the wrapped tail | equality + partial-name control fail | **4 failures** |
| forbidden shape in a *different* file | guard fails | **failed**, named that file |
| render a different patient name | identity assertions fail | **2 failures** |

Mutation residue: **0** (`git status` clean after each revert).

## 8. Durable rules

1. `pdftotext -layout` output is a serialisation of visual layout. A semantically
   continuous field may be split across lines and interleaved with adjacent columns.
   Tests must not assume a wrap-capable dynamic PDF field is one contiguous substring.
2. Tests covering PDF line wrapping must use a **deterministic** value known to exercise
   the wrap. Do not depend on faker probability to enter the wrap branch.
3. If a PDF contains all required semantic content and only extraction contiguity differs,
   **correct the test contract** — never alter valid production escaping or layout to
   satisfy a brittle substring assertion.
4. PDF dynamic-field assertions must be field-aware, or otherwise proven wrap-tolerant
   *and* false-positive resistant. Whole-page whitespace flattening is not an acceptable
   substitute.
5. The Full Suite failure of run 32700184849 is **immutable evidence**. A corrective sprint
   may reproduce it with targeted runs only. No complete-suite execution occurs without a
   new explicit authorisation.
6. `e7b8dde4…` is a **failed closure candidate**: unmerged, undeployed, NO-GO. It must never
   be mistaken for production authority.

## 9. Programme status

This sprint may carry its own GO tag. It does **not** close the stabilization programme.

```
STABILIZATION_PROGRAM_CLOSURE = NO_GO_PENDING_NEW_AUTHORIZED_FULL_SUITE
FULL_SUITE_EXECUTION_COUNT    = 0
NEW_FULL_SUITE_AUTHORIZATION_REQUIRED = true
```
