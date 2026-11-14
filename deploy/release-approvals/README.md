# Release approval records (rules 229 and 231)

A production deploy lands only on the exact commit a record in this directory approves, and (rule 231) only when the
approver signed that record with a key the production host trusts. The canonical rules are
`.cursor/rules/229-exact-approved-release-sha-guard.mdc` and `.cursor/rules/231-release-guard-trust-hardening.mdc`;
the verifier is `scripts/lib/release-approval.sh`; the procedure is `docs/runbooks/production-host-identity.md` §6b
(§6c and §6d for the one-time bootstraps).

## When a record is written

Only after **all** of these, in order:

1. The release commit is merged on the base branch and its required CI is green on that exact commit.
2. The owner has approved, in writing, deploying **that full 40-character SHA** to production (CG-22). Approval to
   implement or to merge is not approval to deploy.
3. The owner's approval is recorded in the evidence document the record names, quoting the full SHA.
4. The owner signs the record's committed bytes on their own workstation (rule 231), after the record is committed
   on the approval branch:
   `git show HEAD:deploy/release-approvals/<release-id>.approval | ssh-keygen -Y sign -f <owner signing key> -n dms-release-approval > deploy/release-approvals/<release-id>.approval.sig`.
   Nobody else can produce that file; an assistant prepares the record, never the signature.

The record, its signature and its evidence document are committed together after the release commit and merged
through a pull request. Nothing else may be merged on the base between the release commit and the deploy except other
approval records, their signatures and Markdown documentation under `docs/`; anything else makes the guard refuse, and
the new commit needs its own approval. Editing a record after it was signed breaks the signature: sign it again.

## Format (contract 2, rule 231)

One file per release: `deploy/release-approvals/<release-id>.approval`, where `<release-id>` is the lowercase sprint
id (letters, digits and `-`). Lines are `KEY=VALUE` or `#` comments. Values are plain: letters, digits and
`. _ : / + -` only, no quotes, no spaces, no `$`. Every key below is required, none may repeat, no other key is
allowed, and the file is at most 4096 bytes. It is parsed, never sourced.

```text
# <release-id> — owner approval recorded in <evidence document>
DMS_APPROVAL_CONTRACT=2
DMS_APPROVAL_RELEASE_ID=<release-id>
DMS_APPROVAL_REPOSITORY=makemesick91-code/new_lab_app
DMS_APPROVAL_HOST=DaengtisiaMS
DMS_APPROVAL_HOST_KEY_SHA256=<DMS_PRODUCTION_HOST_KEY_SHA256 from deploy/production-host.conf>
DMS_APPROVAL_BASE_BRANCH=<the base branch, DMS_PRODUCTION_DEPLOY_BRANCH>
DMS_APPROVAL_TARGET_SHA=<full 40-character commit SHA>
DMS_APPROVAL_TARGET_TREE=<git rev-parse <sha>^{tree}>
DMS_APPROVAL_GO_TAG=<the go_tag in that commit's .sprint/current.yml>
DMS_APPROVAL_APPROVED_BY=owner
DMS_APPROVAL_APPROVED_AT=<YYYY-MM-DDTHH:MM:SSZ, when the owner approved>
DMS_APPROVAL_NOT_AFTER=<YYYY-MM-DDTHH:MM:SSZ, at most 72 hours later>
DMS_APPROVAL_EVIDENCE=docs/sprints/<release-id>.md
```

`APPROVED_BY` must be listed in `DMS_PRODUCTION_RELEASE_APPROVERS` in `deploy/production-host.conf`, and since rule
231 it is also the SSH principal whose signature must verify: the host checks
`<release-id>.approval.sig` against its release trust anchor (`/etc/daengtisiams/release-approvers.allowed_signers`,
on the host only, provisioned by the owner — runbook §6d). Contract 1 (unsigned) records are refused. The record also
names the host key, so it approves exactly one machine, not a hostname.

**Verify a record yourself** (no access to production needed), given the owner's public key in an allowed-signers
file:

```text
ssh-keygen -Y verify -f <allowed-signers file> -I owner -n dms-release-approval \
  -s deploy/release-approvals/<release-id>.approval.sig < deploy/release-approvals/<release-id>.approval
```

Never commit an allowed-signers file, a public or private key, or anything else from the trust anchor here.

## Lifetime

A record is never edited or deleted after it is merged: it is the audit trail of what was approved. An expired record
simply stops working, and (rule 231) so does every record of a release that already completed a deploy on the host:
a release id deploys once, whichever signed version of its record was used. A re-deploy — for example after a
rollback — needs a new owner approval and a new signed record under a new release id (e.g. `<release-id>-r2`). A new deploy of the same release after expiry needs a new owner approval, recorded by adding a
new record under a new release id (for example `<release-id>-r2`) or, if the owner prefers, by a reviewed change to
this one that keeps the history in git.
