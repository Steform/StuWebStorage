#!/usr/bin/env bash
# @brief E2E scenario 1 — single file download integrity (local smoke scale).
# @date 2026-11-02
# @author Stephane H.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SIZE="${1:-64M}"
MODE="${2:-sparse}"
# Convert SIZE like 64M to bytes approx for PHP smoke (supports M/G).
BYTES="$SIZE"
if [[ "$SIZE" =~ ^([0-9]+)M$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 )); fi
if [[ "$SIZE" =~ ^([0-9]+)G$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 * 1024 )); fi

echo "== scenario 01 single-file size=$SIZE mode=$MODE =="
# Core path validation (encrypt + multi-range + ZIP extract) without HTTP auth.
php "$ROOT/tests/e2e/bin/smoke-download-php.php" "$BYTES"
echo "scenario 01 OK"
