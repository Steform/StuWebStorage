#!/usr/bin/env bash
# @brief E2E scenario 4 — virtual ZIP assembled from random byte ranges.
# @date 2026-11-02
# @author Stephane H.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
COUNT="${1:-2}"
SIZE="${2:-8M}"
echo "== scenario 04 resume-zip (delegates to scenario 02 coverage + range reassembly) =="
"$ROOT/tests/e2e/scenarios/02-zip-multi.sh" "$COUNT" "$SIZE"
echo "scenario 04 OK"
