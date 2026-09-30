#!/usr/bin/env bash
# STABILIZATION-1 (Part E) — backup of the Backup Manager ITSELF.
#
# Distinguish this from the backups the product manages for equipment:
#   MANAGED BACKUPS = device configs, handled entirely by the engine/Laravel.
#   SYSTEM BACKUP    = this script: PostgreSQL (all control-plane state) plus
#                       a manifest — never the artifact files themselves
#                       (those already live durably under BACKUP_STORAGE_ROOT
#                       and are not this script's job to duplicate).
#
# Run from the repository root, on the HOST (it shells out to `docker compose
# exec`, same pattern used throughout this project's homologation steps —
# never grants this script access to the Docker socket itself, it just is
# one). Requires: Docker Compose and Python 3 on the host, the stack running,
# and .env present with
# POSTGRES_USER/POSTGRES_DB/POSTGRES_PASSWORD (read by the postgres
# container's own environment, never printed by this script).
#
# CRITICAL: this backup is USELESS for recovery without APP_KEY (it decrypts
# every stored device credential) and without the artifact storage volume.
# This script deliberately does NOT copy APP_KEY anywhere — copying a secret
# key next to its own encrypted data defeats the point of encrypting it.
# APP_KEY must be preserved separately, by you, in a password manager or
# secrets vault — see docs/DISASTER_RECOVERY.md.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

TIMESTAMP="$(date +%Y%m%d%H%M%S)"
OUT_DIR="database/backups"
DUMP_FILE="${OUT_DIR}/system-backup-${TIMESTAMP}.dump"
MANIFEST_FILE="${OUT_DIR}/system-backup-${TIMESTAMP}.manifest.json"

mkdir -p "$OUT_DIR"

echo "==> Dumping PostgreSQL (custom format) to ${DUMP_FILE}"
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --format=custom' > "$DUMP_FILE"

SIZE_BYTES=$(stat -c%s "$DUMP_FILE" 2>/dev/null || stat -f%z "$DUMP_FILE")
if [ "$SIZE_BYTES" -eq 0 ]; then
    echo "!! Dump is empty (0 bytes) — aborting, not writing a manifest for a broken backup." >&2
    rm -f "$DUMP_FILE"
    exit 1
fi

SHA256=$(sha256sum "$DUMP_FILE" | awk '{print $1}')

echo "==> Fetching a non-reversible APP_KEY fingerprint (never the key itself) for later DR verification"
KEY_FINGERPRINT=$(docker compose exec -T app php artisan system:recovery-check --json 2>/dev/null \
    | python3 -c 'import json, sys; checks = json.load(sys.stdin).get("checks", []); print(next((check.get("metadata", {}).get("fingerprint_sha256_16", "unavailable") for check in checks if check.get("check") == "app_key"), "unavailable"), end="")' \
    || echo "unavailable")

GIT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")

cat > "$MANIFEST_FILE" <<JSON
{
  "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "dump_file": "$(basename "$DUMP_FILE")",
  "sha256": "$SHA256",
  "size_bytes": $SIZE_BYTES,
  "format": "postgres_custom",
  "app_git_commit": "$GIT_COMMIT",
  "app_key_fingerprint_sha256_16": "$KEY_FINGERPRINT",
  "requires_for_recovery": [
    "This dump (PostgreSQL control-plane state)",
    "The EXACT APP_KEY that was active at backup time (preserved separately — NOT in this manifest, NOT in this dump)",
    "The backup artifact storage volume (BACKUP_STORAGE_ROOT) — not duplicated by this script",
    "See docs/DISASTER_RECOVERY.md for the full procedure"
  ]
}
JSON

echo "==> Done."
echo "    Dump:     $DUMP_FILE ($SIZE_BYTES bytes, sha256 $SHA256)"
echo "    Manifest: $MANIFEST_FILE"
echo "    APP_KEY fingerprint (for later comparison, NOT the key): $KEY_FINGERPRINT"
echo
echo "!! Reminder: this backup is worthless for recovery without the APP_KEY active right now,"
echo "!! preserved separately and securely. This script never touches or copies it."
