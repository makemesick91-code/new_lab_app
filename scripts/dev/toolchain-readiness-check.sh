#!/usr/bin/env bash
#
# DMS developer toolchain readiness check (READ-ONLY).
#
# Reports which tools a DaengtisiaMS DEVFLOW session on a WORKSTATION can use,
# and the owner action for each missing one. It installs nothing, changes no
# configuration, contacts no server and prints no token, password or key — only
# names, versions and verdicts. Never run it on the production host: production
# carries only the runtime toolchain (scripts/lib/production-host-preflight.sh).
#
#   bash scripts/dev/toolchain-readiness-check.sh [--app-dir DIR]
#
# Verdicts: READY (present and usable), MISSING (required: fix before DEVFLOW),
# OPTIONAL-MISSING (the dependent step is reported NOT RUN). Exit 0 when every
# required tool is READY, 1 otherwise. Procedure and pinned versions:
# docs/runbooks/dev-toolchain-readiness.md.
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
while [ "$#" -gt 0 ]; do
  case "$1" in
    --app-dir) APP_DIR="${2:-}"; shift 2 || exit 2 ;;
    *) echo "usage: bash scripts/dev/toolchain-readiness-check.sh [--app-dir DIR]" >&2; exit 2 ;;
  esac
done

missing_required=0
row() { printf '%-27s %-16s %s\n' "$1" "$2" "$3"; }

# check <name> <required|optional> <command -v name> <version command> <owner action>
check() {
  local name="$1" need="$2" bin="$3" version_cmd="$4" action="$5" version
  if command -v "$bin" >/dev/null 2>&1; then
    version="$(bash -c "$version_cmd" 2>&1 </dev/null | head -1 | tr -s ' ' | cut -c1-70)"
    row "$name" "READY" "$version"
  elif [ "$need" = "required" ]; then
    row "$name" "MISSING" "owner: ${action}"
    missing_required=$((missing_required + 1))
  else
    row "$name" "OPTIONAL-MISSING" "owner: ${action}"
  fi
}

echo "== DaengtisiaMS developer toolchain (read-only) =="
row "TOOL" "VERDICT" "VERSION / OWNER ACTION"
check git        required git        'git --version'           'sudo apt-get install git'
check gh         required gh         'gh --version'            'install the official gh release (checksum-verified)'
check php        required php        'php -r "echo PHP_VERSION;"' 'sudo apt-get install php8.3-cli'
check composer   required composer   'composer --version --no-ansi' 'sudo apt-get install composer'
check node       required node       'node --version'          'install Node from the official distribution'
check npm        required npm        'npm --version'           'installed with Node'
check psql       required psql       'psql --version'          'sudo apt-get install postgresql-client-16'
check ssh        required ssh        'ssh -V'                  'sudo apt-get install openssh-client'
check python3    required python3    'python3 --version'       'sudo apt-get install python3'
check graphify   optional graphify   'graphify --version'      'sudo apt-get install pipx && pipx install graphifyy==0.9.99'
check shellcheck optional shellcheck 'shellcheck --version | sed -n 2p' 'sudo apt-get install shellcheck'
check pipx       optional pipx       'pipx --version'          'sudo apt-get install pipx'

# gh must be authenticated (status only; the token is never printed).
if command -v gh >/dev/null 2>&1; then
  if gh auth status >/dev/null 2>&1; then
    row "gh auth" "READY" "authenticated"
  else
    row "gh auth" "MISSING" "owner: gh auth login (device flow)"
    missing_required=$((missing_required + 1))
  fi
fi

# GNU find is what production runs; another implementation can differ.
# shellcheck disable=SC2185 # a version probe, not a search
if find --version 2>/dev/null | head -1 | grep -q 'GNU findutils'; then
  row "find" "READY" "$(find --version | head -1)"
else
  row "find" "NOTE" "$(find --version 2>/dev/null | head -1) — production and CI use GNU findutils"
fi

# Project tooling inside the checkout.
for tool in pest pint; do
  if [ -x "${APP_DIR}/vendor/bin/${tool}" ]; then
    row "$tool" "READY" "vendor/bin/${tool}"
  else
    row "$tool" "MISSING" "copy vendor/ or run composer install (CG-02; never symlink)"
    missing_required=$((missing_required + 1))
  fi
done

# Project skills the session instructions name.
for skill in limit-saver superpowers-workflow knowledge-base sprint-doc-writer graphify-navigator graphify-refresh; do
  if [ -r "${APP_DIR}/.claude/skills/${skill}/SKILL.md" ]; then
    row "skill:${skill}" "READY" ".claude/skills/${skill}"
  else
    row "skill:${skill}" "MISSING" "restore .claude/skills/${skill}/SKILL.md from the base branch"
    missing_required=$((missing_required + 1))
  fi
done

if [ "$missing_required" -ne 0 ]; then
  echo "TOOLCHAIN_READINESS=MISSING (${missing_required} required)"
  exit 1
fi
echo "TOOLCHAIN_READINESS=READY"
