# Developer toolchain readiness (workstation)

**Status:** ACTIVE from DMS-DEPLOY-PRIVSEP-HARDENING-1 (2026-11-15). Scope: the WSL2 development workstation where
DEVFLOW sessions run. Never install development tools on the production host: it carries only the runtime toolchain
that `scripts/lib/production-host-preflight.sh` checks.

## 1. Check first

```bash
bash scripts/dev/toolchain-readiness-check.sh
```

Read-only: it installs nothing, changes no configuration, contacts no server and prints no secret. It ends with
`TOOLCHAIN_READINESS=READY` (exit 0) or `TOOLCHAIN_READINESS=MISSING` (exit 1). An `OPTIONAL-MISSING` tool means the
step that needs it is reported **NOT RUN**, never as passed.

## 2. What a DEVFLOW session uses

| Component | Kind | Required | Source / install (owner) | Verified 2026-11-15 |
|---|---|---|---|---|
| git, PHP 8.3, Composer 2, Node, npm, psql 16, OpenSSH, Python 3 | CLI | yes | Ubuntu archive / official distributions | git 2.43.0, PHP 8.3.6, Composer 2.7.1, Node 24.22.2 (CI and production: 22), npm 11.19.1, psql 16.15, OpenSSH 9.6p1, Python 3.12.3 |
| GitHub CLI `gh` | CLI | yes | official release, checksum-verified, in `~/.local/bin`; `gh auth login` (device flow) | 2.103.3, authenticated |
| Pest, Pint | project | yes | `vendor/bin` (copy `vendor/` into a worktree or `composer install`; never symlink, CG-02) | present |
| ShellCheck | CLI | optional | `sudo apt-get install -y shellcheck` | 0.9.0 |
| pipx | CLI | optional | `sudo apt-get install -y pipx` | 1.4.3 |
| Graphify | CLI | optional | `pipx install graphifyy==0.9.99` — official source `github.com/Graphify-Labs/graphify`, PyPI `graphifyy` (double y; other `graphify*` packages are unaffiliated) | 0.9.99 |
| Project skills | `.claude/skills/` | yes | in the repository | `limit-saver`, `superpowers-workflow`, `knowledge-base`, `sprint-doc-writer`, `graphify-navigator`, `graphify-refresh`, `diff-review`, `laravel-module-pattern` |
| Built-in skills | Claude Code | — | shipped | `code-review`, `security-review`, `dataviz`, `simplify` |
| Superpowers plugin | Claude Code plugin | optional | owner runs `/plugin install superpowers@claude-plugins-official` (official marketplace, pinned commit), then restarts the session; it adds a session-start hook | not installed (owner decision) |

## 3. MCP servers: none required

| Need | Covered by | Why no MCP |
|---|---|---|
| GitHub | `gh` | authenticated CLI already covers PRs, runs and logs |
| Filesystem | Claude Code's own tools | built in |
| PostgreSQL | `psql` on the workstation's test database | the production database is reached only by read-only checks over SSH, never through an MCP server |
| SSH | OpenSSH with the pinned host key, `scripts/production-target-check.sh --remote` | the pinned-key boundary (CG-14) stays in one place |
| Obsidian | the vault is a git worktree of `docs/` | synced by a detached checkout while Obsidian is closed (CG-02) |
| Browser | `curl` over the canonical URL for smoke | Playwright exists in the official marketplace if a real browser is ever needed |

## 4. Never

- Bootstrap pip with `get-pip.py`, pipe an install script into a shell, or fetch tool binaries through pip/npm
  wrappers: install through the Ubuntu archive or `pipx` from the official package.
- Pin a release younger than two weeks.
- Run `graphify install`, `graphify claude install` or `graphify hook install` without an owner decision: they add a
  skill and hooks that intercept file reads and searches (strict mode can deny them) and edit the size-budgeted
  `CLAUDE.md`. Use only `graphify update .` (AST only, no LLM, no API key); `graphify-out/` is gitignored.
- Edit shell startup files (`pipx ensurepath` included): call `~/.local/bin/<tool>` by its full path instead.
- Put a token, password, private key or host-local identifier in the repository; the repository is public.

## 5. Where configuration lives

- **Repository** (everyone): this runbook, `scripts/dev/toolchain-readiness-check.sh`, `.claude/skills/`,
  `.claude/agents/`, `AGENTS.md`, `CLAUDE.md`.
- **Workstation** (owner only): installed packages, `~/.local/bin`, the pipx venvs, `gh` credentials, SSH keys and
  config, the Claude Code plugins and the assistant's private memory. None of it is committed.
