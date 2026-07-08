#!/usr/bin/env bash
#
# CICD-CTRL-1 — Safe CI Runtime Control gate resolver.
#
# Classifies the changed files of a PR/push conservatively and emits which CI
# gates should run. The core safety rule is DEFAULT-STRONG:
#
#   * If changed files cannot be classified safely -> unknown_high_risk.
#   * If there is ANY uncertainty (no diff, unreachable base, unknown path,
#     dependency / workflow / test / app / config change) -> stronger gate.
#   * The ONLY profile allowed to skip the expensive critical test step is
#     docs_only (changed files are exclusively Markdown docs).
#
# This script never skips security, governance, release-safety, evidence, or
# smoke gates. Those always run in the workflow regardless of this output. It
# only decides whether the expensive Pest regression steps and the additive
# selective module tests run for a given change set.
#
# Output: parseable `key=value` lines on stdout. A human summary goes to stderr.
# With --github-output the key=value lines are also appended to $GITHUB_OUTPUT.
# With --json a JSON object is printed instead of key=value lines.
#
# Usage:
#   scripts/ci/resolve-gates.sh --base origin/<base> --head HEAD
#   scripts/ci/resolve-gates.sh --changed-files-file /tmp/files.txt
#   git diff --name-only A B | scripts/ci/resolve-gates.sh --changed-files-stdin
#
# Safe: read-only. Runs `git diff --name-only` only; never mutates the repo.

set -euo pipefail

BASE=""
HEAD_REF="HEAD"
JSON_OUTPUT=false
GITHUB_OUTPUT_MODE=false
CHANGED_FILES_FILE=""
CHANGED_FILES_STDIN=false

for ((i = 1; i <= $#; i++)); do
    arg="${!i}"
    case "$arg" in
        --base)
            j=$((i + 1)); BASE="${!j:-}"; i=$j ;;
        --base=*) BASE="${arg#*=}" ;;
        --head)
            j=$((i + 1)); HEAD_REF="${!j:-HEAD}"; i=$j ;;
        --head=*) HEAD_REF="${arg#*=}" ;;
        --changed-files-file)
            j=$((i + 1)); CHANGED_FILES_FILE="${!j:-}"; i=$j ;;
        --changed-files-file=*) CHANGED_FILES_FILE="${arg#*=}" ;;
        --changed-files-stdin) CHANGED_FILES_STDIN=true ;;
        --json) JSON_OUTPUT=true ;;
        --github-output) GITHUB_OUTPUT_MODE=true ;;
        *) : ;;
    esac
done

# ---------------------------------------------------------------------------
# Resolve the changed file list.
# ---------------------------------------------------------------------------
CHANGED_FILES=""
RESOLVE_REASON=""
FALLBACK=false

if [[ -n "$CHANGED_FILES_FILE" ]]; then
    if [[ -f "$CHANGED_FILES_FILE" ]]; then
        CHANGED_FILES="$(cat "$CHANGED_FILES_FILE")"
        RESOLVE_REASON="changed files from file"
    else
        FALLBACK=true
        RESOLVE_REASON="changed-files file not found; defaulting to strongest gate"
    fi
elif [[ "$CHANGED_FILES_STDIN" == true ]]; then
    CHANGED_FILES="$(cat)"
    RESOLVE_REASON="changed files from stdin"
else
    if [[ -z "$BASE" ]]; then
        FALLBACK=true
        RESOLVE_REASON="no --base provided; defaulting to strongest gate"
    elif ! git rev-parse --verify "$BASE" >/dev/null 2>&1; then
        FALLBACK=true
        RESOLVE_REASON="base ref '$BASE' unreachable; defaulting to strongest gate"
    else
        if CHANGED_FILES="$(git diff --name-only "$BASE...$HEAD_REF" 2>/dev/null)"; then
            RESOLVE_REASON="git diff ${BASE}...${HEAD_REF}"
        elif CHANGED_FILES="$(git diff --name-only "$BASE" "$HEAD_REF" 2>/dev/null)"; then
            RESOLVE_REASON="git diff ${BASE} ${HEAD_REF} (no merge base)"
        else
            FALLBACK=true
            RESOLVE_REASON="git diff failed; defaulting to strongest gate"
        fi
    fi
fi

# Strip blank lines.
CHANGED_FILES="$(printf '%s\n' "$CHANGED_FILES" | sed '/^[[:space:]]*$/d')"
CHANGED_COUNT=0
if [[ -n "$CHANGED_FILES" ]]; then
    CHANGED_COUNT="$(printf '%s\n' "$CHANGED_FILES" | wc -l | tr -d ' ')"
fi

if [[ "$FALLBACK" == false && "$CHANGED_COUNT" -eq 0 ]]; then
    FALLBACK=true
    RESOLVE_REASON="no changed files detected; defaulting to strongest gate"
fi

# ---------------------------------------------------------------------------
# Classify each file into a category. First matching pattern wins per file.
# Categories, strongest first:
#   unknown_high_risk > ci_workflow > dependency_or_build > permissions_security
#   > runtime_app > ui_only > docs_only
# ---------------------------------------------------------------------------
classify_file() {
    local f="$1"
    case "$f" in
        # --- CI / workflow / scripts (always strong) ---
        .github/workflows/*|.github/*) echo "ci_workflow" ;;
        scripts/*) echo "ci_workflow" ;;
        # --- dependency / build / test-harness config ---
        composer.json|composer.lock) echo "dependency_or_build" ;;
        package.json|package-lock.json|pnpm-lock.yaml|yarn.lock) echo "dependency_or_build" ;;
        vite.config.*|tailwind.config.*|postcss.config.*) echo "dependency_or_build" ;;
        phpunit.xml|phpunit.xml.dist|tests/Pest.php|tests/TestCase.php|.php-cs-fixer*|pint.json) echo "dependency_or_build" ;;
        # --- permissions / security / access control ---
        app/Policies/*|*Policy.php) echo "permissions_security" ;;
        app/Http/Middleware/*|*/Middleware/*) echo "permissions_security" ;;
        database/seeders/*Permission*|database/seeders/*Role*) echo "permissions_security" ;;
        app/Providers/*Auth*|*/BranchContext*|app/Support/*Branch*) echo "permissions_security" ;;
        # --- runtime app code (tests changed => force related tests) ---
        app/*|routes/*|database/*|config/*|bootstrap/*) echo "runtime_app" ;;
        tests/*) echo "runtime_app" ;;
        artisan|composer|*.php) echo "runtime_app" ;;
        # --- UI-only (views / styles / scripts / design docs) ---
        resources/views/*|resources/css/*|resources/js/*|resources/*) echo "ui_only" ;;
        docs/ui/*|docs/ui_design_system.md) echo "ui_only" ;;
        # --- docs-only (Markdown) ---
        docs/*.md|*.md|.cursor/rules/*) echo "docs_only" ;;
        # --- anything else: cannot prove safe ---
        *) echo "unknown_high_risk" ;;
    esac
}

# Module detection (independent of profile) for the selective module gate.
detect_modules() {
    local f="$1"
    case "$f" in
        app/Modules/Inventory/*|*/Inventory/*|resources/views/inventory/*|*inventory*|*Inventory*) echo "inventory" ;;
    esac
    case "$f" in
        app/Modules/*Rme*|*/Rme*|*/RME/*|resources/views/rme/*|*rme*|*Rme*) echo "rme" ;;
    esac
    case "$f" in
        app/Modules/*Lab*|*/Lab*|resources/views/lab/*|*lab-order*|*LabOrder*) echo "lab" ;;
    esac
    case "$f" in
        resources/views/*|resources/css/*|resources/js/*|tests/Feature/Ui/*|docs/ui/*) echo "ui" ;;
    esac
    case "$f" in
        app/Policies/*|*Policy.php|app/Http/Middleware/*|*/Middleware/*|database/seeders/*Permission*|database/seeders/*Role*|*/BranchContext*) echo "permission" ;;
    esac
}

PROFILE="docs_only"
declare -A CATEGORIES=()
declare -A MODULES=()

if [[ "$FALLBACK" == true ]]; then
    PROFILE="unknown_high_risk"
    CATEGORIES["unknown_high_risk"]=1
else
    while IFS= read -r file; do
        [[ -z "$file" ]] && continue
        cat="$(classify_file "$file")"
        CATEGORIES["$cat"]=1
        while IFS= read -r mod; do
            [[ -z "$mod" ]] && continue
            MODULES["$mod"]=1
        done < <(detect_modules "$file")
    done <<< "$CHANGED_FILES"

    # Pick the strongest present category (default-strong precedence).
    for candidate in unknown_high_risk ci_workflow dependency_or_build permissions_security runtime_app ui_only docs_only; do
        if [[ -n "${CATEGORIES[$candidate]:-}" ]]; then
            PROFILE="$candidate"
            break
        fi
    done
fi

# ---------------------------------------------------------------------------
# Gate decisions. DEFAULT-STRONG: everything runs unless the profile is proven
# docs_only. High-risk profiles force all module tests.
# ---------------------------------------------------------------------------
is_high_risk=false
case "$PROFILE" in
    unknown_high_risk|ci_workflow|dependency_or_build) is_high_risk=true ;;
esac

# The single safety invariant: only docs_only skips the critical test step.
if [[ "$PROFILE" == "docs_only" ]]; then
    RUN_CRITICAL_TESTS=false
    RUN_BUILD=false
else
    RUN_CRITICAL_TESTS=true
    RUN_BUILD=true
fi

flag() { if [[ "$is_high_risk" == true || -n "${MODULES[$1]:-}" ]]; then echo true; else echo false; fi; }

RUN_UI_TESTS="$(flag ui)"
RUN_PERMISSION_TESTS="$(flag permission)"
RUN_INVENTORY_TESTS="$(flag inventory)"
RUN_RME_TESTS="$(flag rme)"
RUN_LAB_TESTS="$(flag lab)"

# ui_only / permissions_security profiles always run their own module tests.
[[ "$PROFILE" == "ui_only" ]] && RUN_UI_TESTS=true
[[ "$PROFILE" == "permissions_security" ]] && RUN_PERMISSION_TESTS=true

if [[ "$PROFILE" == "docs_only" ]]; then
    RUN_UI_TESTS=false
    RUN_PERMISSION_TESTS=false
    RUN_INVENTORY_TESTS=false
    RUN_RME_TESTS=false
    RUN_LAB_TESTS=false
fi

# Full-suite fallback recommendation (advisory only; the workflow's own
# schedule/dispatch/push condition is the source of truth and is never
# weakened by this script).
if [[ "$is_high_risk" == true ]]; then
    RUN_FULL_SUITE=required
elif [[ "$PROFILE" == "docs_only" ]]; then
    RUN_FULL_SUITE=false
else
    RUN_FULL_SUITE=manual
fi

CATS_PRESENT="$(printf '%s ' "${!CATEGORIES[@]}" | sed 's/ $//')"

# ---------------------------------------------------------------------------
# Emit.
# ---------------------------------------------------------------------------
{
    echo "CICD-CTRL-1 Safe CI Runtime Control — gate resolution"
    echo "  source            : $RESOLVE_REASON"
    echo "  changed files     : $CHANGED_COUNT"
    echo "  categories present: ${CATS_PRESENT:-none}"
    echo "  gate_profile      : $PROFILE"
    echo "  run_critical_tests: $RUN_CRITICAL_TESTS"
    echo "  run_ui_tests      : $RUN_UI_TESTS"
    echo "  run_permission    : $RUN_PERMISSION_TESTS"
    echo "  run_inventory     : $RUN_INVENTORY_TESTS"
    echo "  run_rme           : $RUN_RME_TESTS"
    echo "  run_lab           : $RUN_LAB_TESTS"
    echo "  run_build         : $RUN_BUILD"
    echo "  run_full_suite    : $RUN_FULL_SUITE"
    if [[ "$RUN_CRITICAL_TESTS" == false ]]; then
        echo "  SAFETY            : docs_only change — expensive Pest steps skipped; governance/security/release-safety/evidence gates still run."
    else
        echo "  SAFETY            : stronger gate selected — critical Pest regression runs. Uncertainty always resolves to the stronger gate."
    fi
} >&2

emit_kv() {
    cat <<EOF
gate_profile=$PROFILE
run_critical_tests=$RUN_CRITICAL_TESTS
run_ui_tests=$RUN_UI_TESTS
run_permission_tests=$RUN_PERMISSION_TESTS
run_inventory_tests=$RUN_INVENTORY_TESTS
run_rme_tests=$RUN_RME_TESTS
run_lab_tests=$RUN_LAB_TESTS
run_build=$RUN_BUILD
run_full_suite=$RUN_FULL_SUITE
changed_file_count=$CHANGED_COUNT
classification_reason=$RESOLVE_REASON
EOF
}

if [[ "$JSON_OUTPUT" == true ]]; then
    cat <<EOF
{
  "gate_profile": "$PROFILE",
  "run_critical_tests": $RUN_CRITICAL_TESTS,
  "run_ui_tests": $RUN_UI_TESTS,
  "run_permission_tests": $RUN_PERMISSION_TESTS,
  "run_inventory_tests": $RUN_INVENTORY_TESTS,
  "run_rme_tests": $RUN_RME_TESTS,
  "run_lab_tests": $RUN_LAB_TESTS,
  "run_build": $RUN_BUILD,
  "run_full_suite": "$RUN_FULL_SUITE",
  "changed_file_count": $CHANGED_COUNT,
  "classification_reason": "$RESOLVE_REASON"
}
EOF
else
    emit_kv
fi

if [[ "$GITHUB_OUTPUT_MODE" == true && -n "${GITHUB_OUTPUT:-}" ]]; then
    emit_kv >> "$GITHUB_OUTPUT"
fi

exit 0
