#!/usr/bin/env bash
#
# Atlas database restore — DESTRUCTIVE. Overwrites all data in the target
# database with the contents of the given dump. See
# docs/operations/Backup-and-Recovery.md for the full restore procedure and
# the restore-drill checklist this script supports.
#
# Usage (interactive — prompts for confirmation):
#   DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... \
#     ./atlas-db-restore.sh <dump-file>
#
# Usage (non-interactive — for scripted restore drills):
#   ./atlas-db-restore.sh <dump-file> --yes --confirm-database=<name>
#   (fails unless <name> exactly matches DB_DATABASE)
#
# Fails loudly on any error (`set -euo pipefail`, ON_ERROR_STOP=on so a
# single failed statement aborts the restore instead of silently
# continuing). Never proceeds without explicit confirmation of the exact
# target database name — there is no "restore without asking" mode. Never
# logs DB_PASSWORD or any other secret.

set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"

log() {
    printf '[%s] atlas-db-restore: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"
}

DUMP_FILE="${1:?Usage: atlas-db-restore.sh <dump-file> [--yes --confirm-database=<name>]}"
shift || true

ASSUME_YES=false
CONFIRM_DB_ARG=""
for arg in "$@"; do
    case "${arg}" in
        --yes) ASSUME_YES=true ;;
        --confirm-database=*) CONFIRM_DB_ARG="${arg#--confirm-database=}" ;;
    esac
done

if [ ! -f "${DUMP_FILE}" ]; then
    log "FAILED: dump file not found: ${DUMP_FILE}"
    exit 1
fi

if [[ "${DUMP_FILE}" == *.gpg ]]; then
    log "FAILED: file is gpg-encrypted — decrypt it first (gpg --decrypt) before restoring"
    exit 1
fi

echo "WARNING: this will PERMANENTLY OVERWRITE all data in database '${DB_DATABASE}' on ${DB_HOST}:${DB_PORT}."
echo "This is destructive and cannot be undone."

if [ "${ASSUME_YES}" = true ]; then
    if [ "${CONFIRM_DB_ARG}" != "${DB_DATABASE}" ]; then
        log "FAILED: --yes requires --confirm-database=${DB_DATABASE} to match exactly (got '${CONFIRM_DB_ARG}')"
        exit 1
    fi
    log "confirmed non-interactively via --confirm-database"
else
    # `|| TYPED=""` keeps a closed/empty stdin (EOF) from tripping `set -e`
    # and skipping the ABORTED message below — an unattended/non-interactive
    # invocation without --yes must still abort loudly, not exit silently.
    read -r -p "Type the database name (${DB_DATABASE}) to confirm: " TYPED || TYPED=""
    if [ "${TYPED}" != "${DB_DATABASE}" ]; then
        log "ABORTED: confirmation did not match — no changes made"
        exit 1
    fi
fi

export PGPASSWORD="${DB_PASSWORD:-}"

log "starting restore of ${DUMP_FILE} into '${DB_DATABASE}' on ${DB_HOST}:${DB_PORT}"

RESTORE_OK=true
if [[ "${DUMP_FILE}" == *.gz ]]; then
    if ! gunzip -c "${DUMP_FILE}" | psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" --set ON_ERROR_STOP=on --quiet; then
        RESTORE_OK=false
    fi
else
    if ! psql --host="${DB_HOST}" --port="${DB_PORT}" --username="${DB_USERNAME}" --dbname="${DB_DATABASE}" --set ON_ERROR_STOP=on --quiet --file="${DUMP_FILE}"; then
        RESTORE_OK=false
    fi
fi

unset PGPASSWORD

if [ "${RESTORE_OK}" = false ]; then
    log "FAILED: restore did not complete successfully — target database may be in a partial state"
    exit 1
fi

log "SUCCESS: restore complete"
