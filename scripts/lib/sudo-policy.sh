# shellcheck shell=bash
#
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — classify an account's EFFECTIVE sudo
# rights from sudo's own long listing (`sudo -ll -U <user>`), never from a text
# search of the sudoers files: sudo resolves aliases, groups and includes itself.
#
# Sourced (never executed) by scripts/sudo-policy-check.sh and the tests.
#
# dms_sudo_classify <entrypoint> <declared admin: 0|1>  < listing
#   prints one line:  <VERDICT>[ <reason>]
#
#   NONE            no sudo rights at all
#   OPERATOR        every rule runs ONLY <entrypoint> with exactly one of the
#                   rendered argument expressions, as root (no group), with no
#                   option but NOPASSWD and no per-command setting; the account
#                   has no command-specific Defaults, keeps env_reset and a
#                   secure_path, and never sets setenv, env_keep, runchroot or runcwd
#   ADMIN_PASSWORD  the declared administrator; every rule needs its password,
#                   one of them reaches root, and no Defaults bypass or redirect
#                   the password (!authenticate, rootpw, targetpw, runaspw)
#   VIOLATION       anything else — passwordless ALL above all (OD-6), unrestricted
#                   sudo for an account that is not the declared administrator, a
#                   command that is not the entrypoint, a broad argument
#                   expression, SETENV, a non-root run-as user, or a weakened
#                   environment reset
#
# Returns 0 for NONE, OPERATOR and ADMIN_PASSWORD, 1 for VIOLATION, 2 on usage.

# Turns the listing into records separated by the ASCII unit separator (0x1f) —
# never whitespace, so `read` keeps an empty field as a field:
#   D<US><default>                       one per Matching Defaults item
#   R<US><line>                          one per Runas/Command-specific Defaults line
#   C<US><run-as users><US><run-as groups><US><options><US><extra><US><command>
#                                        one per command; <extra> is 1 when the
#                                        entry carries a Chroot or Cwd line
_dms_sudo_records() {
  awk -v US=$'\x1f' '
    function trim(s) { sub(/^[ \t]+/, "", s); sub(/[ \t]+$/, "", s); return s }
    /^Matching Defaults entries for / { section = "defaults"; next }
    /^Runas and Command-specific defaults for / { section = "cmddefaults"; next }
    /^User .* may run the following commands on / { section = "rules"; next }
    /^User .* is not allowed to run sudo on / { section = ""; next }
    section == "defaults" && /^[ \t]+[^ \t]/ {
      n = split(trim($0), items, /, /)
      for (i = 1; i <= n; i++) print "D" US trim(items[i])
      next
    }
    section == "cmddefaults" && /[^ \t]/ { print "R" US trim($0); next }
    (section == "defaults" || section == "cmddefaults") && /^$/ { section = ""; next }
    section == "rules" && /^Sudoers entry:/ { runas = "root"; groups = ""; opts = ""; extra = 0; incmds = 0; next }
    section == "rules" && /^[ \t]+RunAsUsers:/ { s = $0; sub(/^[ \t]+RunAsUsers:[ \t]*/, "", s); runas = trim(s); next }
    section == "rules" && /^[ \t]+RunAsGroups:/ { s = $0; sub(/^[ \t]+RunAsGroups:[ \t]*/, "", s); groups = trim(s); next }
    section == "rules" && /^[ \t]+Options:/ { s = $0; sub(/^[ \t]+Options:[ \t]*/, "", s); opts = trim(s); next }
    section == "rules" && /^[ \t]+(Chroot|Cwd|Type|Role|Apparmor_Profile|Limitprivs|Privs|NotBefore|NotAfter|Timeout):/ { extra = 1; next }
    section == "rules" && /^[ \t]+Commands:/ { incmds = 1; next }
    section == "rules" && incmds && /^\t/ { print "C" US runas US groups US opts US extra US trim($0); next }
    section == "rules" && /^$/ { incmds = 0; next }
  '
}

# True when the comma-separated sudo option list contains <name> (not !<name>).
_dms_sudo_has_option() {
  local list="$1" name="$2" item
  local -a items=()
  IFS=',' read -r -a items <<< "$list"
  for item in "${items[@]}"; do
    item="${item#"${item%%[![:space:]]*}"}"
    item="${item%"${item##*[![:space:]]}"}"
    [ "$item" = "$name" ] && return 0
  done
  return 1
}

# The exact argument expressions the deploy operator may run the entrypoint
# with — the single source for the rendered rule and for the classifier.
dms_sudo_operator_expressions() {
  printf '%s\n' \
    '^(status|follow|trust-check|drift-check|sudo-policy-check|posture)$' \
    '^verify [0-9]{8}-[0-9]{6}$' \
    '^start --release-id [a-z0-9][a-z0-9-][a-z0-9-]+ --approved-sha [0-9a-f]{40}$'
}

# The PATH the operator's sudo runs the entrypoint with (rendered secure_path).
readonly DMS_SUDO_OPERATOR_SECURE_PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin

dms_sudo_classify() {
  local entrypoint="${1:-}" is_admin="${2:-}" kind a b c d e item expr
  local commands=0 all_cmds=0 all_root_password=0 nopasswd_all=0 operator_cmds=0 reason=""
  local -a defaults=() cmd_defaults=() allowed=()
  if [ -z "$entrypoint" ] || { [ "$is_admin" != 0 ] && [ "$is_admin" != 1 ]; }; then
    echo "usage: dms_sudo_classify <entrypoint> <0|1> < listing" >&2
    return 2
  fi
  while IFS= read -r expr; do allowed+=("${entrypoint} ${expr}"); done < <(dms_sudo_operator_expressions)
  while IFS=$'\x1f' read -r kind a b c d e; do
    case "$kind" in
      D) defaults+=("$a"); continue ;;
      R) cmd_defaults+=("$a"); continue ;;
      C) ;;
      *) continue ;;
    esac
    # a=run-as users  b=run-as groups  c=options  d=chroot/cwd flag  e=command
    commands=$((commands + 1))
    if _dms_sudo_has_option "$c" setenv; then
      [ -n "$reason" ] || reason="SETENV lets the caller pass environment variables"
    fi
    if [ "$e" = "ALL" ]; then
      all_cmds=$((all_cmds + 1))
      if _dms_sudo_has_option "$c" '!authenticate'; then
        nopasswd_all=$((nopasswd_all + 1))
      elif [ "$a" = "ALL" ] || [ "$a" = "root" ]; then
        all_root_password=$((all_root_password + 1))
      fi
      continue
    fi
    local exact=0 candidate
    for candidate in "${allowed[@]}"; do
      [ "$e" = "$candidate" ] && exact=1
    done
    if [ "$exact" -ne 1 ]; then
      [ -n "$reason" ] || reason="[${e}] is not one of the deploy entrypoint's rendered requests"
    elif [ "$a" != "root" ] || [ -n "$b" ]; then
      [ -n "$reason" ] || reason="the entrypoint runs as [${a}${b:+:${b}}], not only root"
    elif [ "$d" != 0 ]; then
      [ -n "$reason" ] || reason="the entrypoint rule carries a Chroot, Cwd or other per-command setting"
    elif [ -n "$c" ] && [ "$c" != "!authenticate" ]; then
      [ -n "$reason" ] || reason="the entrypoint rule carries options other than NOPASSWD [${c}]"
    else
      operator_cmds=$((operator_cmds + 1))
    fi
  done < <(_dms_sudo_records)

  if [ "$commands" -eq 0 ]; then
    echo "NONE"
    return 0
  fi
  if [ "$nopasswd_all" -gt 0 ]; then
    echo "VIOLATION unrestricted passwordless sudo (NOPASSWD: ALL)"
    return 1
  fi
  if [ "$all_cmds" -gt 0 ] && [ "$is_admin" != 1 ]; then
    echo "VIOLATION unrestricted sudo for an account that is not the declared administrator"
    return 1
  fi
  if [ -n "$reason" ]; then
    echo "VIOLATION ${reason}"
    return 1
  fi
  if [ "$all_cmds" -gt 0 ]; then
    # The administrator: password-only, and a password rule that reaches root.
    if [ "$operator_cmds" -gt 0 ]; then
      echo "VIOLATION the administrator also holds the operator entrypoint — keep the roles on separate accounts"
      return 1
    fi
    for item in "${defaults[@]}" "${cmd_defaults[@]}"; do
      case "$item" in
        '!authenticate'|*'!authenticate'*|rootpw|targetpw|runaspw|*' rootpw'*|*' targetpw'*|*' runaspw'*|timestamp_timeout=-*)
          echo "VIOLATION the administrator's Defaults weaken or redirect its password ([${item}])"
          return 1
          ;;
      esac
    done
    if [ "$all_root_password" -eq 0 ]; then
      echo "VIOLATION no password rule gives the administrator root (RunAsUsers ALL or root)"
      return 1
    fi
    echo "ADMIN_PASSWORD"
    return 0
  fi
  # The deploy operator: nothing per-command, a clean environment, a fixed PATH.
  if [ "${#cmd_defaults[@]}" -gt 0 ]; then
    echo "VIOLATION the operator carries run-as or command-specific Defaults [${cmd_defaults[0]}]"
    return 1
  fi
  local has_reset=0 has_path=0
  for item in "${defaults[@]}"; do
    case "$item" in
      env_reset) has_reset=1 ;;
      secure_path=*) has_path=1 ;;
      '!env_reset'|setenv|env_keep*|env_file*|restricted_env_file*|runchroot*|runcwd*|env_delete-=*|'!secure_path'|'!authenticate'|rootpw|targetpw|runaspw)
        echo "VIOLATION the operator's Defaults weaken the environment, PATH or authentication ([${item}])"
        return 1
        ;;
    esac
  done
  if [ "$has_reset" -ne 1 ] || [ "$has_path" -ne 1 ]; then
    echo "VIOLATION the operator's Defaults do not keep env_reset and a secure_path"
    return 1
  fi
  echo "OPERATOR"
  return 0
}

# dms_sudo_render_operator_rule <operator user> <entrypoint>
# The sudoers drop-in for the deploy operator: the entrypoint only, with
# argument expressions that match exactly the requests it accepts
# (scripts/deploy-operator-entrypoint.sh enforces the 80-character release-id
# bound itself; sudo expressions carry no `,`, which sudoers would read as a
# list separator). No SETENV, no other command, root only.
dms_sudo_render_operator_rule() {
  local user="${1:-}" entrypoint="${2:-}" expr sep=""
  [[ "$user" =~ ^[a-z][a-z0-9-]{2,31}$ ]] || { echo "invalid operator account name" >&2; return 2; }
  [[ "$entrypoint" =~ ^/[A-Za-z0-9/._-]+$ ]] || { echo "invalid entrypoint path" >&2; return 2; }
  cat <<RULE
# DMS-DEPLOY-PRIVSEP-PHASE-2 (rule 235) — rendered by scripts/provision-deploy-identities.sh.
# The deploy operator may run ONLY the root-owned deploy entrypoint, as root,
# with arguments matching these expressions. Never edit by hand: re-render.
Defaults:${user} env_reset, !setenv, secure_path="${DMS_SUDO_OPERATOR_SECURE_PATH}"
RULE
  printf '%s ALL=(root) NOPASSWD: ' "$user"
  while IFS= read -r expr; do
    printf '%s%s %s' "$sep" "$entrypoint" "$expr"
    sep=$', \\\n    '
  done < <(dms_sudo_operator_expressions)
  printf '\n'
}
