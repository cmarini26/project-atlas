#!/usr/bin/env bash
#
# Atlas database backup — provider-neutral pg_dump wrapper.
#
# Reads connection info from the same DB_* environment variables Laravel
# itself uses (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD), so
# it works unmodified against whatever managed PostgreSQL provider is
# chosen. See docs/operations/Backup-and-Recovery.md for the full backup
# strategy (retention, encryption, off-site requirements) this script is
# one piece of — installing and scheduling this script does not, by
# itself, mean backups are operational; see that document's "Code-complete
# vs. operator-complete" section.
#
# Usage:
#   DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
#     ./atlas-db-backup.sh [destination-dir]
#
# Optional env vars:
#   BACKUP_RETENTION_DAYS   - delete local dumps older than N days (default: no pruning)
#   BACKUP_GPG_RECIPIENT    - if set, encrypt the dump with `gpg --encrypt -r <recipient>`
#   BACKUP_OFFSITE_COMMAND  - if set, a shell command template run after a
#                             successful local backup, with {file} replaced
#                             by the backup's path (e.g. an `aws s3 cp`,
#                             `rclone copy`, or `b2 upload-file` invocation).
#                             A failure here is logged loudly but does not
#                             delete the (still valid) local backup.
#
# Fails loudly: `set -euo pipefail`, explicit required-variable checks, a
# non-zero exit on any failure, and an empty-file check so a silently
# truncated dump is never mistaken for a successful backup. Never logs
# DB_PASSWORD or any other secret.

set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"

DESTINATION_DIR="${1:-${BACKUP_DESTINATION_DIR:-./backups}}"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"

log() {
    printf '[%s] atlas-db-backup: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
}

mkdir -p "${DESTINATION_DIR}"

FINAL_NAME="atlas-${DB_DATABASE}-${TIMESTAMP}.sql.gz"
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
    FINAL_NAME="${FINAL_NAME}.gpg"
fi
FINAL_PATH="${DESTINATION_DIR}/${FINAL_NAME}"
RAW_DUMP="${DESTINATION_DIR}/.atlas-dump-${TIMESTAMP}.tmp"

log "starting backup of database '${DB_DATABASE}' on ${DB_HOST}:${DB_PORT} -> ${FINAL_PATH}"

export PGPASSWORD="${DB_PASSWORD:-}"

if ! pg_dump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --username="${DB_USERNAME}" \
    --dbname="${DB_DATABASE}" \
    --format=plain \
    --no-owner \
    --no-privileges \
    | gzip > "${RAW_DUMP}"; then
    unset PGPASSWORD
    log "FAILED: pg_dump did not complete successfully"
    rm -f "${RAW_DUMP}"
    exit 1
fi

unset PGPASSWORD

if [ ! -s "${RAW_DUMP}" ]; then
    log "FAILED: dump is empty — refusing to treat this as a successful backup"
    rm -f "${RAW_DUMP}"
    exit 1
fi

if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
    log "encrypting dump for recipient ${BACKUP_GPG_RECIPIENT}"
    if ! gpg --yes --batch --trust-model always --encrypt -r "${BACKUP_GPG_RECIPIENT}" --output "${FINAL_PATH}" "${RAW_DUMP}"; then
        log "FAILED: gpg encryption did not complete successfully"
        rm -f "${RAW_DUMP}" "${FINAL_PATH}"
        exit 1
    fi
    rm -f "${RAW_DUMP}"
else
    mv "${RAW_DUMP}" "${FINAL_PATH}"
fi

SIZE_BYTES=$(wc -c < "${FINAL_PATH}" | tr -d ' ')
log "SUCCESS: backup complete (${SIZE_BYTES} bytes) -> ${FINAL_PATH}"

if [ -n "${BACKUP_OFFSITE_COMMAND:-}" ]; then
    OFFSITE_CMD="${BACKUP_OFFSITE_COMMAND//\{file\}/${FINAL_PATH}}"
    log "uploading off-site: ${OFFSITE_CMD}"
    if ! bash -c "${OFFSITE_CMD}"; then
        log "WARNING: off-site upload failed — local backup at ${FINAL_PATH} is still valid, but no off-site copy exists yet for this run"
    else
        log "off-site upload complete"
    fi
fi

if [ -n "${BACKUP_RETENTION_DAYS:-}" ]; then
    log "pruning local dumps older than ${BACKUP_RETENTION_DAYS} days in ${DESTINATION_DIR}"
    find "${DESTINATION_DIR}" -maxdepth 1 -name 'atlas-*.sql.gz*' -mtime "+${BACKUP_RETENTION_DAYS}" -print -delete | while read -r pruned; do
        log "pruned old local backup: ${pruned}"
    done
fi
