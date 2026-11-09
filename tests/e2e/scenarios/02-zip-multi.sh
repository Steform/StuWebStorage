#!/usr/bin/env bash
# @brief E2E scenario 2 — multi-entry virtual ZIP (local smoke).
# @date 2026-11-02
# @author Stephane H.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
COUNT="${1:-3}"
SIZE="${2:-8M}"
BYTES=8388608
if [[ "$SIZE" =~ ^([0-9]+)M$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 )); fi
if [[ "$SIZE" =~ ^([0-9]+)G$ ]]; then BYTES=$(( ${BASH_REMATCH[1]} * 1024 * 1024 * 1024 )); fi
echo "== scenario 02 zip-multi count=$COUNT size=$SIZE =="
php "$ROOT/tests/e2e/bin/smoke-zip-multi-php.php" "$COUNT" "$BYTES"
echo "scenario 02 OK"
