#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

if [[ ! -f "artisan" ]]; then
  echo "ERROR: Not a Laravel project root. Run from /mnt/DATA/new_lab_app." >&2
  exit 1
fi

HOOK_PATH=".git/hooks/pre-commit"

if [[ ! -d ".git/hooks" ]]; then
  echo "ERROR: .git/hooks directory not found. Is this a git repository?" >&2
  exit 1
fi

if [[ -f "$HOOK_PATH" ]]; then
  BACKUP="${HOOK_PATH}.backup.$(date +%Y%m%d_%H%M%S)"
  echo "WARNING: $HOOK_PATH already exists."
  echo "Backing up existing hook to: $BACKUP"
  cp "$HOOK_PATH" "$BACKUP"
  echo "Review the backup before proceeding if needed."
  echo ""
fi

cat > "$HOOK_PATH" << 'HOOK_CONTENT'
#!/usr/bin/env bash
# pre-commit hook — installed by scripts/install-git-hooks.sh
# Runs Pint on dirty files only. Tests are NOT run here (they are slow).
# To skip this hook for a single commit: git commit --no-verify

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [[ ! -f "vendor/bin/pint" ]]; then
  echo "Pint not found — skipping format check."
  exit 0
fi

echo "[pre-commit] Running Pint on dirty files..."
./vendor/bin/pint --dirty

echo "[pre-commit] Format check passed."
HOOK_CONTENT

chmod +x "$HOOK_PATH"

echo "Git pre-commit hook installed at: $HOOK_PATH"
echo ""
echo "The hook will run: ./vendor/bin/pint --dirty   on every commit."
echo "Full tests are NOT included in the hook (run: make test  manually)."
echo ""
echo "To remove the hook:"
echo "  rm $PROJECT_ROOT/$HOOK_PATH"
echo ""
echo "To skip the hook for a single commit:"
echo "  git commit --no-verify"
