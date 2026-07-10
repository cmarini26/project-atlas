#!/usr/bin/env bash
#
# Atlas backup verification — a lightweight integrity check on a dump
# produced by atlas-db-backup.sh: confirms the archive isn't truncated or
# corrupt and actually contains schema. This is NOT a substitute for the
# periodic full restore drill described in
# docs/operations/Backup-and-Recovery.md — passing this check means "the
# file isn't obviously broken," not "this backup is confirmed restorable."
#
# Usage:
#   ./atlas-db-verify.sh <dump-file>

set -euo pipefail

DUMP_FILE="${1:?Usage: atlas-db-verify.sh <dump-file>}"

log() {
    printf '[%s] atlas-db-verify: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
}

if [ ! -s "${DUMP_FILE}" ]; then
    log "FAILED: file missing or empty: ${DUMP_FILE}"
    exit 1
fi

if [[ "${DUMP_FILE}" == *.gpg ]]; then
    log "dump is gpg-encrypted — content integrity can only be checked after decryption; file presence/size only"
    exit 0
fi

if [[ "${DUMP_FILE}" == *.gz ]]; then
    if ! gzip -t "${DUMP_FILE}"; then
        log "FAILED: gzip integrity check failed for ${DUMP_FILE}"
        exit 1
    fi
    TABLE_COUNT=$(gunzip -c "${DUMP_FILE}" | grep -c '^CREATE TABLE' || true)
else
    TABLE_COUNT=$(grep -c '^CREATE TABLE' "${DUMP_FILE}" || true)
fi

if [ "${TABLE_COUNT}" -eq 0 ]; then
    log "FAILED: dump contains no CREATE TABLE statements — likely an empty or corrupt schema dump"
    exit 1
fi

log "SUCCESS: ${DUMP_FILE} is intact and contains ${TABLE_COUNT} table definition(s)"
