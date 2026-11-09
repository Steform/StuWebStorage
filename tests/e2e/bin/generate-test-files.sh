#!/usr/bin/env bash
# @brief Generate large test files (sparse or real) with SHA-256 manifests.
# @date 2026-11-02
# @author Stephane H.
set -euo pipefail

SIZE="1G"
MODE="sparse"
COUNT=1
OUTDIR="${E2E_WORKDIR:-/tmp/e2e-dl100}/files"
PREFIX="e2e-dl100"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --size) SIZE="$2"; shift 2 ;;
    --mode) MODE="$2"; shift 2 ;;
    --count) COUNT="$2"; shift 2 ;;
    --outdir) OUTDIR="$2"; shift 2 ;;
    --prefix) PREFIX="$2"; shift 2 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

mkdir -p "$OUTDIR"
MANIFEST="$OUTDIR/manifest.json"
echo "[" > "$MANIFEST"
FIRST=1

parse_size() {
  local raw="$1"
  local num unit
  num="$(echo "$raw" | sed -E 's/^([0-9]+).*/\1/')"
  unit="$(echo "$raw" | sed -E 's/^[0-9]+//;s/i//;s/B//' | tr '[:lower:]' '[:upper:]')"
  case "$unit" in
    ""|B) echo "$num" ;;
    K) echo $((num * 1024)) ;;
    M) echo $((num * 1024 * 1024)) ;;
    G) echo $((num * 1024 * 1024 * 1024)) ;;
    T) echo $((num * 1024 * 1024 * 1024 * 1024)) ;;
    *) echo "Bad size: $raw" >&2; exit 2 ;;
  esac
}

BYTES="$(parse_size "$SIZE")"

for i in $(seq 1 "$COUNT"); do
  NAME="${PREFIX}-$(printf '%02d' "$i")-${SIZE}-${MODE}.bin"
  PATH_OUT="$OUTDIR/$NAME"
  rm -f "$PATH_OUT"
  if [[ "$MODE" == "sparse" ]]; then
    truncate -s "$BYTES" "$PATH_OUT"
    # Place a few non-zero blocks so SHA is not trivial all-zero for tiny checks.
    dd if=/dev/urandom of="$PATH_OUT" bs=4096 count=1 conv=notrunc status=none 2>/dev/null || true
    dd if=/dev/urandom of="$PATH_OUT" bs=4096 count=1 seek=$((BYTES/4096 > 1 ? BYTES/4096 - 1 : 0)) conv=notrunc status=none 2>/dev/null || true
  else
    # Real data: stream urandom (slow for huge sizes).
    dd if=/dev/urandom of="$PATH_OUT" bs=8M count=$(( (BYTES + 8388607) / 8388608 )) status=progress 2>/dev/null || \
      head -c "$BYTES" /dev/urandom > "$PATH_OUT"
    truncate -s "$BYTES" "$PATH_OUT"
  fi
  HASH="$(sha256sum "$PATH_OUT" | awk '{print $1}')"
  ACTUAL="$(stat -c%s "$PATH_OUT")"
  if [[ "$FIRST" -eq 0 ]]; then
    echo "," >> "$MANIFEST"
  fi
  FIRST=0
  printf '  {"name":"%s","path":"%s","size":%s,"sha256":"%s","mode":"%s"}' \
    "$NAME" "$PATH_OUT" "$ACTUAL" "$HASH" "$MODE" >> "$MANIFEST"
  echo "generated $NAME size=$ACTUAL sha=$HASH"
done

echo "" >> "$MANIFEST"
echo "]" >> "$MANIFEST"
echo "manifest=$MANIFEST"
