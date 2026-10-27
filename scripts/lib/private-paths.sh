# shellcheck shell=bash
#
# SECURITY-F8-PRIVATE-STORAGE-PATHS-1 — the single definition of what a declared
# private storage path may look like, and how two declarations combine.
#
# Sourced (never executed) by scripts/deploy-vps.sh and, when given
# --additional-private-paths, by scripts/verify-runtime-isolation.sh. Both read
# it from the immutable deployment snapshot during a deploy.
#
# Why a union exists at all: the deploy sources the runtime identity authority
# (deploy/runtime-identity.conf) from the LIVE host copy, before it fast-forwards
# to the release it is installing. A release that adds a private path would
# therefore only be protected from the NEXT deploy on, and the deploy that ships
# it would widen that path to 2775/0664 like any other storage directory. The
# deploy now combines the live declaration with the target release's
# declaration. A union can only ADD protection: a release can never remove a path
# the host already treats as private.

# A private path is a relative path under storage/, one or more plain segments,
# or exactly bootstrap/cache. No absolute paths, no "..", no empty segments, no
# glob characters: the value is used as a find(1) -path pattern and as a chmod
# target.
#
# SECURITY-F8B-CONFIG-CACHE-PRIVATE-1: bootstrap/cache is the one private path
# outside storage/. `php artisan config:cache` writes the resolved configuration
# there — the database password and the application key included — so it holds
# every secret the environment file holds. It is admitted by exact name only;
# nothing else under bootstrap/ may be declared.
dms_private_path_is_valid() {
  local rel="${1:-}"
  case "$rel" in
    "" | /* | */ | *//* ) return 1 ;;
  esac
  case "/${rel}/" in
    */../* | */./* ) return 1 ;;
  esac
  [[ "$rel" =~ ^storage(/[A-Za-z0-9._-]+)+$ || "$rel" == "bootstrap/cache" ]]
}

# Print the union of one or more space-separated path lists, in first-seen
# order, duplicates dropped. Returns 1 (printing nothing) when any entry is
# invalid, so a caller can fail closed. Lists are split with `read -a`, which
# never performs pathname expansion.
dms_private_paths_union() {
  local -a out=() parts=()
  local list rel seen=" "
  for list in "$@"; do
    read -r -a parts <<< "$list"
    for rel in "${parts[@]}"; do
      if ! dms_private_path_is_valid "$rel"; then
        echo "dms_private_paths_union: invalid private storage path '${rel}'" >&2
        return 1
      fi
      case "$seen" in
        *" ${rel} "*) continue ;;
      esac
      seen+="${rel} "
      out+=("$rel")
    done
  done
  printf '%s' "${out[*]}"
}
