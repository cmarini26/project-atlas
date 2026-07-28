#!/usr/bin/env bash
#
# Atlas customer-upload backup — archives Laravel's public disk while the
# application still uses local storage. This must be scheduled alongside the
# database backup. See docs/operations/Backup-and-Recovery.md.
#
# Usage:
#   ./atlas-files-backup.sh <source-dir> [destination-dir]
#
# Optional env vars:
#   BACKUP_RETENTION_DAYS  - prune local file archives older than N days
#   BACKUP_GPG_RECIPIENT   - encrypt the archive for this GPG recipient
#   BACKUP_OFFSITE_COMMAND - command template with {file} replaced by archive

set -euo pipefail

SOURCE_DIR="${1:-${FILES_BACKUP_SOURCE_DIR:-}}"
DESTINATION_DIR="${2:-${BACKUP_DESTINATION_DIR:-./backups}}"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"

log() {
    printf '[%s] atlas-files-backup: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
}

if [ -z "${SOURCE_DIR}" ]; then
    log "FAILED: source directory is required"
    exit 1
fi

if [ ! -d "${SOURCE_DIR}" ]; then
    log "FAILED: source directory does not exist: ${SOURCE_DIR}"
    exit 1
fi

mkdir -p "${DESTINATION_DIR}"

FINAL_NAME="atlas-files-${TIMESTAMP}.tar.gz"
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
    FINAL_NAME="${FINAL_NAME}.gpg"
fi
FINAL_PATH="${DESTINATION_DIR}/${FINAL_NAME}"
RAW_ARCHIVE="${DESTINATION_DIR}/.atlas-files-${TIMESTAMP}.tmp.tar.gz"

log "starting backup of ${SOURCE_DIR} -> ${FINAL_PATH}"

if ! tar -czf "${RAW_ARCHIVE}" -C "${SOURCE_DIR}" .; then
    log "FAILED: file archive did not complete successfully"
    rm -f "${RAW_ARCHIVE}"
    exit 1
fi

if [ ! -s "${RAW_ARCHIVE}" ] || ! tar -tzf "${RAW_ARCHIVE}" >/dev/null; then
    log "FAILED: file archive is empty or corrupt"
    rm -f "${RAW_ARCHIVE}"
    exit 1
fi

if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
    if ! gpg --yes --batch --trust-model always --encrypt -r "${BACKUP_GPG_RECIPIENT}" --output "${FINAL_PATH}" "${RAW_ARCHIVE}"; then
        log "FAILED: gpg encryption did not complete successfully"
        rm -f "${RAW_ARCHIVE}" "${FINAL_PATH}"
        exit 1
    fi
    rm -f "${RAW_ARCHIVE}"
else
    mv "${RAW_ARCHIVE}" "${FINAL_PATH}"
fi

SIZE_BYTES=$(wc -c < "${FINAL_PATH}" | tr -d ' ')
log "SUCCESS: backup complete (${SIZE_BYTES} bytes) -> ${FINAL_PATH}"

if [ -n "${BACKUP_OFFSITE_COMMAND:-}" ]; then
    OFFSITE_CMD="${BACKUP_OFFSITE_COMMAND//\{file\}/${FINAL_PATH}}"
    if ! bash -c "${OFFSITE_CMD}"; then
        log "WARNING: off-site upload failed — local backup remains valid, but this run has no off-site copy"
    else
        log "off-site upload complete"
    fi
fi

if [ -n "${BACKUP_RETENTION_DAYS:-}" ]; then
    find "${DESTINATION_DIR}" -maxdepth 1 -name 'atlas-files-*.tar.gz*' -mtime "+${BACKUP_RETENTION_DAYS}" -print -delete | while read -r pruned; do
        log "pruned old local backup: ${pruned}"
    done
fi
