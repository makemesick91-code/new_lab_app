#!/usr/bin/env bash
#
# CICD-CTRL-3 — run a command inside the authoritative PHP 8.3 CI runtime.
#
# The self-hosted runner's host PHP is NOT the authoritative runtime. Ubuntu
# 26.04 ships only PHP 8.5, while the GitHub-hosted gate pins PHP 8.3, so every
# php/composer/artisan invocation in the self-hosted variant goes through this
# wrapper and executes inside the pinned image built from
# .github/ci-runtime/Containerfile.php83.
#
# Isolation properties:
#   - ROOTLESS Podman, run as the dedicated github-runner service user.
#   - github-runner is NOT in the docker group and there is no rootful Docker
#     involved; the docker group is root-equivalent and is forbidden here.
#   - --userns=keep-id maps the container user to the host github-runner UID, so
#     files the container writes are owned by github-runner and the workspace
#     never accumulates root-owned residue.
#   - --network=host lets the container reach ONLY the runner's local, loopback
#     bound CI PostgreSQL. No production database is reachable, and the
#     production-database guard runs inside this same runtime before migrations.
#
# Usage:
#   scripts/ci/self-hosted-php.sh php artisan test --filter=...
#   scripts/ci/self-hosted-php.sh composer install --no-interaction
#
set -euo pipefail

CI_PHP_IMAGE="${CI_PHP_IMAGE:-localhost/daengtisia-ci-php:8.3}"

if ! command -v podman >/dev/null 2>&1; then
    echo "CICD-CTRL-3: podman is not installed on this runner." >&2
    exit 1
fi

# Fail loudly rather than silently falling back to a different PHP.
if ! podman image exists "$CI_PHP_IMAGE"; then
    echo "CICD-CTRL-3: authoritative CI image '${CI_PHP_IMAGE}' is not present." >&2
    echo "Build it with: podman build -t ${CI_PHP_IMAGE} -f .github/ci-runtime/Containerfile.php83 ." >&2
    exit 1
fi

if [ "$#" -eq 0 ]; then
    echo "CICD-CTRL-3: no command given to ${0##*/}." >&2
    exit 2
fi

exec podman run --rm \
    --network=host \
    --userns=keep-id \
    --volume "$PWD:/workspace" \
    --workdir /workspace \
    --env APP_ENV \
    --env APP_DEBUG \
    --env APP_KEY \
    --env DB_CONNECTION \
    --env DB_HOST \
    --env DB_PORT \
    --env DB_DATABASE \
    --env DB_USERNAME \
    --env DB_PASSWORD \
    --env CACHE_STORE \
    --env QUEUE_CONNECTION \
    --env SESSION_DRIVER \
    --env MAIL_MAILER \
    --env FILESYSTEM_DISK \
    "$CI_PHP_IMAGE" \
    "$@"
