# Release approval records (rule 229)

A production deploy lands only on the exact commit a record in this directory approves. The canonical rule is
`.cursor/rules/229-exact-approved-release-sha-guard.mdc`; the verifier is `scripts/lib/release-approval.sh`; the
procedure is `docs/runbooks/production-host-identity.md` §6b (and §6c for the one-time bootstrap).

## When a record is written

Only after **all** of these, in order:

1. The release commit is merged on the base branch and its required CI is green on that exact commit.
2. The owner has approved, in writing, deploying **that full 40-character SHA** to production (CG-22). Approval to
   implement or to merge is not approval to deploy.
3. The owner's approval is recorded in the evidence document the record names, quoting the full SHA.

The record and its evidence document are committed together after the release commit and merged through a pull
request. Nothing else may be merged on the base between the release commit and the deploy except other approval
records and Markdown documentation under `docs/`; anything else makes the guard refuse, and the new commit needs its
own approval.

## Format (contract 1)

One file per release: `deploy/release-approvals/<release-id>.approval`, where `<release-id>` is the lowercase sprint
id (letters, digits and `-`). Lines are `KEY=VALUE` or `#` comments. Values are plain: letters, digits and
`. _ : / + -` only, no quotes, no spaces, no `$`. Every key below is required, none may repeat, no other key is
allowed, and the file is at most 4096 bytes. It is parsed, never sourced.

```text
# <release-id> — owner approval recorded in <evidence document>
DMS_APPROVAL_CONTRACT=1
DMS_APPROVAL_RELEASE_ID=<release-id>
DMS_APPROVAL_REPOSITORY=makemesick91-code/new_lab_app
DMS_APPROVAL_HOST=DaengtisiaMS
DMS_APPROVAL_BASE_BRANCH=<the base branch, DMS_PRODUCTION_DEPLOY_BRANCH>
DMS_APPROVAL_TARGET_SHA=<full 40-character commit SHA>
DMS_APPROVAL_TARGET_TREE=<git rev-parse <sha>^{tree}>
DMS_APPROVAL_GO_TAG=<the go_tag in that commit's .sprint/current.yml>
DMS_APPROVAL_APPROVED_BY=owner
DMS_APPROVAL_APPROVED_AT=<YYYY-MM-DDTHH:MM:SSZ, when the owner approved>
DMS_APPROVAL_NOT_AFTER=<YYYY-MM-DDTHH:MM:SSZ, at most 72 hours later>
DMS_APPROVAL_EVIDENCE=docs/sprints/<release-id>.md
```

`APPROVED_BY` must be listed in `DMS_PRODUCTION_RELEASE_APPROVERS` in `deploy/production-host.conf`. It is an
attribution, not an authentication: what makes a record trustworthy is that it reaches the base branch only through a
merged pull request on the canonical repository, and that the production host reads it from the fetched commit
object.

## Lifetime

A record is never edited or deleted after it is merged: it is the audit trail of what was approved. An expired record
simply stops working. A new deploy of the same release after expiry needs a new owner approval, recorded by adding a
new record under a new release id (for example `<release-id>-r2`) or, if the owner prefers, by a reviewed change to
this one that keeps the history in git.
