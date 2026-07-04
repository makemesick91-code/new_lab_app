#!/usr/bin/env bash
# NSF-9 — Automated release smoke (read-only, safe for CI and VPS).
# Never mutates data. Exits non-zero on FAIL (WATCH/GO both exit 0).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

BASE_URL="${BASE_URL:-}"

if [[ -n "$BASE_URL" ]]; then
    php artisan release:automated-smoke --base-url="$BASE_URL"
else
    php artisan release:automated-smoke
fi
