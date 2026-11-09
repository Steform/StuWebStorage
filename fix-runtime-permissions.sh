#!/usr/bin/env bash
# @brief Create and chmod Symfony runtime directories for the PHP / Apache user.
# @date 2026-11-09
# @author Stephane H.
#
# Run this as the account that executes PHP (Debian/Apache: www-data).
#   sudo -u www-data ./fix-runtime-permissions.sh
#
# If directories are owned by the deploy user, fix ownership once as root:
#   sudo ./fix-runtime-permissions.sh --web-user www-data
#
# Check only (no mkdir/chmod):
#   ./fix-runtime-permissions.sh --check
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
CHECK_ONLY=0
WEB_USER=""
WEB_GROUP=""

# Runtime trees PHP must create/write (see docs/epics/EPIC-PERM-droits-runtime.md).
RUNTIME_DIRS=(
    "var"
    "var/cache"
    "var/log"
    "var/sessions"
    "var/shared"
    "var/chunk_upload"
    "var/tasks"
    "var/extract"
    "var/download_prepare"
    "var/bug-reports"
    "var/config"
)

DIR_MODE="2775"
FILE_MODE="0664"

# @brief Print usage and exit.
# @param int $1 Exit code.
# @return void
# @date 2026-11-09
# @author Stephane H.
usage() {
    local code="${1:-0}"
    cat <<EOF
Usage: $0 [--check] [--web-user USER] [--web-group GROUP]

  Ensure StuWebStorage runtime directories under var/ are writable by PHP.

Options:
  --check           Verify write access only (no mkdir, chmod, or chown)
  --web-user USER   Root only: chown directories to USER before chmod
  --web-group GROUP Root only: group for chown (default: same as --web-user)
  -h, --help        Show this help

Typical Debian / Apache + php-fpm:
  sudo -u www-data $0
  sudo $0 --web-user www-data

Do not chmod the application source. This script only touches var/ runtime trees.
EOF
    exit "$code"
}

# @brief Return 0 if the current user is root.
# @return int
# @date 2026-11-09
# @author Stephane H.
is_root() {
    [[ "$(id -u)" -eq 0 ]]
}

# @brief Probe that a directory is writable by the current user.
# @param string $1 Absolute directory path.
# @return int 0 on success.
# @date 2026-11-09
# @author Stephane H.
probe_write() {
    local dir="$1"
    local probe="${dir}/.perm_probe_$$"
    if ! touch "$probe" 2>/dev/null; then
        return 1
    fi
    rm -f "$probe"
    return 0
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --check)
            CHECK_ONLY=1
            shift
            ;;
        --web-user)
            WEB_USER="${2:-}"
            if [[ -z "$WEB_USER" ]]; then
                echo "ERROR: --web-user requires a user name." >&2
                usage 1
            fi
            shift 2
            ;;
        --web-group)
            WEB_GROUP="${2:-}"
            if [[ -z "$WEB_GROUP" ]]; then
                echo "ERROR: --web-group requires a group name." >&2
                usage 1
            fi
            shift 2
            ;;
        -h|--help)
            usage 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage 1
            ;;
    esac
done

if [[ -n "$WEB_USER" ]] && ! is_root; then
    echo "ERROR: --web-user requires root (use sudo)." >&2
    exit 1
fi

if [[ -n "$WEB_USER" && -z "$WEB_GROUP" ]]; then
    WEB_GROUP="$WEB_USER"
fi

CURRENT_USER="$(id -un)"
echo "Project root : $ROOT"
echo "Running as   : $CURRENT_USER (uid=$(id -u))"
if [[ -n "$WEB_USER" ]]; then
    echo "Web owner    : ${WEB_USER}:${WEB_GROUP}"
fi
if [[ "$CHECK_ONLY" -eq 1 ]]; then
    echo "Mode         : check only"
else
    echo "Mode         : apply (mkdir + chmod ${DIR_MODE})"
fi
echo

FAILED=0

for rel in "${RUNTIME_DIRS[@]}"; do
    abs="${ROOT}/${rel}"
    if [[ "$CHECK_ONLY" -eq 0 ]]; then
        if [[ ! -d "$abs" ]]; then
            if ! mkdir -p "$abs"; then
                echo "FAIL  mkdir  ${rel}"
                FAILED=1
                continue
            fi
            echo "OK    mkdir  ${rel}"
        fi
        if [[ -n "$WEB_USER" ]]; then
            if ! chown "${WEB_USER}:${WEB_GROUP}" "$abs"; then
                echo "FAIL  chown  ${rel}"
                FAILED=1
                continue
            fi
        fi
        if ! chmod "$DIR_MODE" "$abs"; then
            echo "FAIL  chmod  ${rel}"
            FAILED=1
            continue
        fi
        if [[ -f "${abs}/.gitkeep" ]]; then
            chmod "$FILE_MODE" "${abs}/.gitkeep" 2>/dev/null || true
        fi
    else
        if [[ ! -d "$abs" ]]; then
            echo "FAIL  missing ${rel}"
            FAILED=1
            continue
        fi
    fi

    if ! probe_write "$abs"; then
        owner_info="$(stat -c '%U:%G %a' "$abs" 2>/dev/null || echo '?')"
        echo "FAIL  write  ${rel}  (${owner_info})"
        FAILED=1
        continue
    fi
    echo "OK    write  ${rel}"
done

echo
if [[ "$FAILED" -ne 0 ]]; then
    cat >&2 <<EOF
ERROR: one or more runtime directories are not writable by ${CURRENT_USER}.

If you are the PHP/Apache user and directories belong to the deploy account:
  sudo ${ROOT}/fix-runtime-permissions.sh --web-user ${CURRENT_USER}

Then retry:
  sudo -u ${CURRENT_USER} ${ROOT}/fix-runtime-permissions.sh --check

On Debian/Apache the PHP user is usually www-data:
  sudo ./fix-runtime-permissions.sh --web-user www-data
  sudo -u www-data ./fix-runtime-permissions.sh --check
EOF
    exit 1
fi

echo "All runtime directories are writable by ${CURRENT_USER}."
exit 0
