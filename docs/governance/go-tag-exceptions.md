# GO tag naming exceptions (CG-16)

**Authority:** CLAUDE.md CG-16. **Introduced by:** DMS-BIZNET-POST-GO-DOCUMENTATION-CLOSURE-1 (2026-11-04).

## The rule

A GO tag is annotated, named by the lowercase `go_tag` declared in `.sprint/current.yml` (ending `-go`), and created
only after merge, deploy and production verification. An existing tag is never moved, recreated or deleted.

## The one permitted exception

When a **single production deploy releases more than one sprint**, the owner may name the release GO tag **in
writing**, in the deploy authorization. All of the following must hold:

1. The name matches the `go_tag` pattern (`^[a-z0-9]+(?:-[a-z0-9]+)*-go$`).
2. It is still created only after merge, deploy and production verification, on the deployed commit.
3. Every sprint released by that deploy has its manifest `go_tag` listed below as **superseded**. The superseded tags
   are **not created** afterwards unless the owner separately authorizes it.
4. The tag message names the authorization and the superseded `go_tag` values.
5. The tag is entered in the registry below **before** this registry is cited as permitting it.

Nothing else is an exception. In particular:

- A single-sprint deploy uses its manifest `go_tag`, even if the owner prefers another name.
- A docs, rules or tests-only change is closed without a deploy and receives no GO tag of its own.
- An infrastructure release is not exempt merely for being infrastructure: the condition is "more than one sprint in
  one deploy" plus a written owner naming.
- A superseded `go_tag` that later appears on any commit is a governance violation, not a backfill.

## Registry

### `dms-biznet-first-production-deploy-infra-1-go`

| Field | Value |
|---|---|
| Tag object | `a58aedfeeccd7db826a679f913cceba9fa7aed9e` (annotated) |
| Target commit | `6f22beb77c1648811bf10c2208afc0b75502252c` |
| Created | 2026-11-04T15:35:31Z, after deploy and production verification |
| Authorization | DMS-BIZNET-FIRST-PRODUCTION-DEPLOYMENT-GO-1, §15 (System Owner, written) |
| Deploy | `20261104-151042` on Biznet `DaengtisiaMS`, proven with `verify-transition` |
| Sprints released | DMS-POST-MIGRATION-DEV-INFRA-ALIGNMENT-1 (#586, #587, #588); DMS-SCANNER-AGENT-TRUSTED-ORIGIN-HARDENING-1 (#589) |
| Superseded `go_tag` | `dms-post-migration-dev-infra-alignment-1-go` |
| Superseded `go_tag` | `dms-scanner-agent-trusted-origin-hardening-1-go` |
| Superseded tags created | none |
| Known gap | The tag predates this registry and its message names only `dms-scanner-agent-trusted-origin-hardening-1-go`. This registry is the complete record; the tag is not modified to close the gap (CG-16 forbids recreating it). |
