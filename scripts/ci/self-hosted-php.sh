#!/usr/bin/env bash
#
# CICD-CTRL-3 — run a command inside the authoritative PHP CI runtime.
#
# The contract this script enforces is SEMANTIC RUNTIME EQUIVALENCE, not a
# particular container engine: whatever executes the command must provide the
# same PHP major.minor, the same extension set, and the same Poppler binaries as
# the GitHub-hosted critical gate. How that runtime is supplied is an
# implementation detail of the host.
#
# Two modes satisfy the contract:
#
#   native  — the host itself provides the authoritative PHP. Correct when the
#             host OS ships the pinned PHP version (Ubuntu 24.04 ships 8.3).
#             Fewer moving parts: no image build, no rootless plumbing, no
#             userns mapping.
#
#   podman  — the runtime comes from the pinned image built from
#             .github/ci-runtime/Containerfile.php83, run ROOTLESS as the
#             dedicated service user. Required when the host OS cannot supply
#             the authoritative PHP version (Ubuntu 26.04 ships only 8.5, which
#             is why this wrapper originally existed).
#
# Docker is forbidden in both modes: the docker group is root-equivalent.
#
# Mode is chosen by CI_RUNTIME_MODE (native|podman|auto). `auto` prefers a host
# PHP that already matches the authoritative version and falls back to podman.
# There is NO silent fallback to a mismatched PHP: every path below either
# proves version and extension parity or exits non-zero.
#
# Usage:
#   scripts/ci/self-hosted-php.sh php artisan test --filter=...
#   scripts/ci/self-hosted-php.sh composer install --no-interaction
#   scripts/ci/self-hosted-php.sh --print-runtime
#
# `--print-runtime` reports the RESOLVED runtime as key=value evidence and runs
# nothing else. CI evidence must describe what actually executed: a previous
# revision printed "engine=rootless podman" unconditionally, producing a run
# whose own log read `container_engine=` (empty) while claiming Podman on a host
# that has none. The mode is derived here so no caller has to assume it.
#
set -euo pipefail

CI_RUNTIME_MODE="${CI_RUNTIME_MODE:-auto}"
CI_PHP_IMAGE="${CI_PHP_IMAGE:-localhost/daengtisia-ci-php:8.3}"
REQUIRED_PHP_VERSION="${CI_RUNNER_PHP_VERSION:-8.3}"
REQUIRED_EXTENSIONS="${CI_RUNTIME_REQUIRED_EXTENSIONS:-dom curl libxml mbstring zip pcntl pdo pdo_pgsql bcmath gd exif}"
REQUIRED_BINARIES="${CI_RUNTIME_REQUIRED_BINARIES:-pdfinfo pdftoppm}"

if [ "$#" -eq 0 ]; then
    echo "CICD-CTRL-3: no command given to ${0##*/}." >&2
    exit 2
fi

host_php_version() {
    command -v php >/dev/null 2>&1 || return 1
    php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null
}

# Parity assertions for the native runtime. A missing extension would make the
# affected tests SKIP rather than run, which would silently make the self-hosted
# gate weaker than the authoritative one — so this fails instead.
assert_native_parity() {
    local actual
    actual="$(host_php_version || true)"

    if [ "$actual" != "$REQUIRED_PHP_VERSION" ]; then
        echo "CICD-CTRL-3: host PHP is '${actual:-absent}'; the authoritative gate requires ${REQUIRED_PHP_VERSION}." >&2
        return 1
    fi

    # The module list is captured ONCE and matched without a pipeline.
    #
    # `php -m | grep -qix "$ext"` looks equivalent but races: `grep -q` exits at
    # the first match, `php -m` then dies of SIGPIPE, and `set -o pipefail`
    # reports the pipeline as failed even though the extension WAS found — so a
    # present extension is recorded as missing. Measured on the CI host, `gd`
    # (14th of 53 modules, early enough for grep to exit first) was falsely
    # reported missing in 1 of 40 trials; across 11 extensions that failed a
    # required gate. A here-string has no pipeline and no such race.
    local modules missing=""
    modules="$(php -m)"
    for extension in $REQUIRED_EXTENSIONS; do
        grep -qix "$extension" <<<"$modules" || missing="${missing} ${extension}"
    done
    if [ -n "$missing" ]; then
        echo "CICD-CTRL-3: host PHP is missing required extension(s):${missing}" >&2
        return 1
    fi

    for binary in $REQUIRED_BINARIES; do
        command -v "$binary" >/dev/null 2>&1 || {
            echo "CICD-CTRL-3: required binary '${binary}' is not installed on the host." >&2
            return 1
        }
    done

    # Pest exhausts the default 128M limit inside TestSuiteLoader before a single
    # test executes; the GitHub-hosted gate runs with no limit.
    if [ "$(php -r 'echo ini_get("memory_limit");')" != "-1" ]; then
        echo "CICD-CTRL-3: host PHP memory_limit must be -1 to match the authoritative gate." >&2
        return 1
    fi

    return 0
}

resolve_mode() {
    case "$CI_RUNTIME_MODE" in
        native|podman)
            echo "$CI_RUNTIME_MODE"
            ;;
        auto)
            if [ "$(host_php_version || true)" = "$REQUIRED_PHP_VERSION" ]; then
                echo native
            elif command -v podman >/dev/null 2>&1; then
                echo podman
            else
                echo none
            fi
            ;;
        *)
            echo "CICD-CTRL-3: unknown CI_RUNTIME_MODE '${CI_RUNTIME_MODE}' (expected native, podman or auto)." >&2
            exit 2
            ;;
    esac
}

MODE="$(resolve_mode)"

# Evidence-only mode: describe the resolved runtime, execute nothing. Emitted as
# key=value so CI evidence, the step summary and tests all read the same facts.
if [ "$1" = "--print-runtime" ]; then
    case "$MODE" in
        native)
            echo "runtime_mode=native"
            echo "container_engine=none"
            echo "php_source=host"
            echo "php_version=$(host_php_version || echo unknown)"
            ;;
        podman)
            echo "runtime_mode=container"
            echo "container_engine=podman"
            echo "container_rootless=$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null || echo unknown)"
            echo "php_source=image"
            echo "container_image=${CI_PHP_IMAGE}"
            ;;
        *)
            # No runtime satisfies the contract. Report it and fail: an
            # inconsistent runtime must never be described as usable.
            echo "runtime_mode=unsatisfied"
            echo "container_engine=none"
            echo "php_source=none"
            ;;
    esac

    if [ "$MODE" = "native" ] || [ "$MODE" = "podman" ]; then
        exit 0
    fi

    echo "CICD-CTRL-3: no runtime satisfies the authoritative contract on this host." >&2
    exit 1
fi

case "$MODE" in
    native)
        assert_native_parity || {
            echo "CICD-CTRL-3: native runtime does not match the authoritative gate; refusing to run." >&2
            exit 1
        }
        exec "$@"
        ;;

    podman)
        if ! command -v podman >/dev/null 2>&1; then
            echo "CICD-CTRL-3: podman runtime requested but podman is not installed." >&2
            exit 1
        fi

        # Fail loudly rather than silently falling back to a different PHP.
        if ! podman image exists "$CI_PHP_IMAGE"; then
            echo "CICD-CTRL-3: authoritative CI image '${CI_PHP_IMAGE}' is not present." >&2
            echo "Build it with: podman build -t ${CI_PHP_IMAGE} -f .github/ci-runtime/Containerfile.php83 ." >&2
            exit 1
        fi

        # --userns=keep-id maps the container user to the host service user, so
        # the workspace never accumulates root-owned residue.
        # --network=host reaches ONLY the runner's loopback-bound CI PostgreSQL.
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
        ;;

    *)
        echo "CICD-CTRL-3: no runtime satisfies the authoritative contract on this host." >&2
        echo "Provide PHP ${REQUIRED_PHP_VERSION} natively, or install podman and build ${CI_PHP_IMAGE}." >&2
        exit 1
        ;;
esac
