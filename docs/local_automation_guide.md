# Local Automation Guide

## What This Does

A set of Bash scripts and a Makefile that automate common repetitive development tasks
for this Laravel project. They run entirely on your local machine — no Claude Code session required.

Scripts live in `scripts/` and are invoked via `make <target>` from the project root.

---

## Why This Saves Claude Code Limit

Every time Claude reads files, analyzes code, runs commands, or edits through the agent
session, it consumes your Claude Code usage limit.

**Local automation does not consume any Claude Code limit.**

By running `make check` or `make context` yourself, you get the same output that Claude
would generate, without paying for an agent session. You only need Claude when you
have something specific to ask — not to gather boilerplate status information.

Rule of thumb:
- **Local terminal** → gather information, run checks, format code, generate snapshots.
- **Claude Code / ChatGPT** → analyze problems, design solutions, write or review code.

---

## Available Commands

| Command | Script | Purpose |
|---|---|---|
| `make status` | `scripts/status.sh` | Fast branch/commit/diff summary |
| `make test` | `scripts/test.sh` | Run all PHPUnit/Laravel tests |
| `make format` | `scripts/format.sh` | Run Laravel Pint on dirty files |
| `make routes` | `scripts/routes.sh` | Save route list to `storage/logs/` |
| `make check` | `scripts/check.sh` | Full quality gate (tests + pint + routes) |
| `make log-check` | `scripts/log-check.sh` | Run full check and save timestamped log |
| `make tail-check` | `scripts/tail-last-check.sh` | Print last 120 lines of most recent log |
| `make sprint-finish-check` | `scripts/sprint-finish-check.sh` | Pre-commit checklist for sprint/sub-phase finish |
| `make context` | `scripts/context-snapshot.sh` | Generate context snapshot for pasting to AI |
| `make install-hooks` | `scripts/install-git-hooks.sh` | Install optional git pre-commit hook |

---

## Daily Development Workflow

### 1. Start of session — check current state

```bash
make status
```

Shows branch, last 5 commits, short git status, and diff stat.
Use this before coding to orient yourself.

### 2. Code with Claude / Cursor

Ask Claude or Cursor to implement the feature or fix.
Keep the session focused: paste only relevant context, not entire files.

Use `make context` to generate a compact snapshot you can paste:

```bash
make context
cat storage/logs/context-snapshot.txt
```

### 3. After coding — run full check

```bash
make check
```

Runs tests, Pint formatting check, and saves route snapshot.
If it passes cleanly — you are ready to commit.

### 4. If check fails — inspect the error

```bash
make log-check      # runs check and saves output to a timestamped log
make tail-check     # prints last 120 lines of that log
```

Copy only the relevant error block from the output and paste it to Claude/ChatGPT.
**Do not paste the entire log.** Identify the specific failing test or Pint error first.

---

## Sprint Finish Workflow

When completing a sprint or sub-phase:

```bash
make sprint-finish-check
```

This runs the full check (tests, format, route snapshot) and then **prints manual next steps**:

1. Review changed files with `git status` and `git diff --name-only`.
2. Update `docs/` if architecture or sprint notes need updating.
3. Stage and commit manually:
   ```bash
   git add <specific files>
   git commit -m "sprint-XX: your message"
   ```
4. Tag manually if the sprint/sub-phase is complete:
   ```bash
   git tag sprint-XX-done
   ```
5. Push manually when ready:
   ```bash
   git push
   git push --tags
   ```

**This script never commits, tags, or pushes automatically.**

---

## Git Hook Installation (Optional)

To automatically run Pint before every commit:

```bash
make install-hooks
```

This installs a `pre-commit` hook that runs `./vendor/bin/pint --dirty`.
Full tests are **not** included in the hook because they are slow.

To remove the hook:
```bash
rm .git/hooks/pre-commit
```

To skip the hook for one commit:
```bash
git commit --no-verify
```

If a `pre-commit` hook already exists, the installer backs it up automatically before overwriting.

---

## Inspecting Failed Logs

All check logs are saved under `storage/logs/`:

```
storage/logs/local-check-YYYY-MM-DD-HHMM.log
storage/logs/route-list-check.txt
storage/logs/context-snapshot.txt
```

To see recent logs:
```bash
ls -lt storage/logs/local-check-*.log | head -5
```

To tail the latest:
```bash
make tail-check
```

---

## Important Rules

- Local scripts do **not** consume Claude Code limit.
- Claude Code limit is consumed only when Claude reads, analyzes, edits, or runs tasks
  through an active agent session.
- These scripts never auto-commit, auto-tag, or auto-push.
- These scripts never modify `.env` or expose secrets.
- These scripts never delete files or run destructive commands.
- All scripts require running from the project root (`/mnt/DATA/new_lab_app`).

---

## Assumptions and Limitations

- PHP, Composer, and `vendor/` must be installed and up to date.
- `./vendor/bin/pint` must exist (installed via Composer).
- PostgreSQL must be running for tests that require the database.
- Scripts are written for Ubuntu / Linux bash environments.
- `set -euo pipefail` is used where safe; `log-check.sh` relaxes this to capture failing exit codes.
