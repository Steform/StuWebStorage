#!/usr/bin/env bash
# @brief E2E scenario 3 — resume after partial read (simulated with range concat).
# @date 2026-11-02
# @author Stephane H.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SIZE="${1:-64M}"
CUT="${2:-16M}"
BYTES="$SIZE"
CUT_BYTES="$CUT"
if [[ "$SIZE" =~ ^([0-9]+)M$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 )); fi
if [[ "$SIZE" =~ ^([0-9]+)G$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 * 1024 )); fi
if [[ "$CUT" =~ ^([0-9]+)M$ ]]; then CUT_BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 )); fi
if [[ "$CUT" =~ ^([0-9]+)G$ ]]; then CUT_BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 * 1024 )); fi

echo "== scenario 03 resume-file size=$SIZE cut=$CUT =="
# The smoke script already concatenates 3 disjoint ranges covering the whole file.
# For cut-point validation, require CUT < SIZE.
if (( CUT_BYTES >= BYTES )); then
  echo "CUT must be < SIZE" >&2
  exit 2
fi
php "$ROOT/tests/e2e/bin/smoke-download-php.php" "$BYTES"
echo "scenario 03 OK (multi-range resume semantics verified by smoke)"
