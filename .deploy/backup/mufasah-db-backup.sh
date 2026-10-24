#!/usr/bin/env bash
#
# Nightly MariaDB backup for the Mufasah Device Tracking database.
#
# Pipeline: mariadb-dump -> integrity checks -> gzip -> upload to Google Drive
# -> prune old copies. Runs as root from /etc/cron.d/mufasah-backup and uses
# MariaDB's unix_socket auth, so no database password is stored on disk.
#
# Usage:
#   mufasah-db-backup.sh              normal cron run
#   DB_NAME=other mufasah-db-backup.sh
#
# Overridable environment:
#   DB_NAME, BACKUP_DIR, RCLONE_REMOTE, LOCAL_RETENTION_DAYS,
#   REMOTE_RETENTION_DAYS, RCLONE_REMOTE_NAME, MIN_SIZE_KB
#
# Exit codes: 0 success, 1 failure (cron logs it, nothing is silently skipped).

set -Eeuo pipefail

# Dumps contain customer names and phone numbers; keep everything root-only.
umask 077

DB_NAME="${DB_NAME:-mufasah_tracking}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mufasah/db}"
RCLONE_REMOTE_NAME="${RCLONE_REMOTE_NAME:-gdrive}"
RCLONE_REMOTE="${RCLONE_REMOTE:-${RCLONE_REMOTE_NAME}:MufasahBackups/db}"
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-14}"
REMOTE_RETENTION_DAYS="${REMOTE_RETENTION_DAYS:-90}"
MONTHLY_RETENTION_DAYS="${MONTHLY_RETENTION_DAYS:-365}"
LOCK_FILE="${LOCK_FILE:-/var/lock/mufasah-db-backup.lock}"

# The schema currently occupies ~7 MB on disk but only ~0.9 MB of actual SQL,
# which gzips to ~110 KB (most of the on-disk size is empty index pages). A
# compressed dump below this floor means something went wrong quietly - wrong
# database, empty stream, truncated output - so treat it as a failure. The
# "Dump completed" and table-count checks below catch truncation that still
# clears this floor.
MIN_SIZE_KB="${MIN_SIZE_KB:-64}"

# Tables that must appear in every valid dump. Their absence means the dump is
# structurally wrong even if it is non-empty.
REQUIRED_TABLES=(users branches devices inventory_batches sales migrations)

log() { printf '%s %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*"; exit 1; }

# Verification failures must not leave a file behind that looks like a usable
# backup. Upload failures keep the local dump; verification failures do not.
verify_fail() { rm -f "$TARGET"; die "$*"; }

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Another backup is already running; skipping this run."
    exit 0
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

STAMP="$(date -u +%Y-%m-%d_%H%M%S)"
FILENAME="${DB_NAME}_${STAMP}.sql.gz"
TARGET="${BACKUP_DIR}/${FILENAME}"
PARTIAL="${TARGET}.partial"

cleanup() { rm -f "$PARTIAL"; }
trap cleanup EXIT

log "Starting backup of ${DB_NAME}"

# --single-transaction gives a consistent snapshot of the InnoDB tables without
# locking them; --quick streams rows instead of buffering the whole table.
if ! mariadb-dump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --no-tablespaces \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip -9 >"$PARTIAL"; then
    die "mariadb-dump failed"
fi

mv "$PARTIAL" "$TARGET"

# --- Verify before anything is deleted or uploaded -------------------------

[[ -s "$TARGET" ]] || verify_fail "dump file is empty"

if ! gzip -t "$TARGET"; then
    verify_fail "gzip integrity check failed"
fi

SIZE_KB="$(du -k "$TARGET" | cut -f1)"
if ((SIZE_KB < MIN_SIZE_KB)); then
    verify_fail "dump is only ${SIZE_KB} KB, below the ${MIN_SIZE_KB} KB floor"
fi

LIVE_TABLES="$(mariadb -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = '${DB_NAME}' AND table_type = 'BASE TABLE'")"

# Read the dump once into a table list. Piping gzip into `grep -q` would make
# grep exit on first match, gzip die of SIGPIPE, and `pipefail` report a false
# failure for any table that is not last in the dump.
DUMPED_TABLE_LIST="$(gzip -dc "$TARGET" | sed -n 's/^CREATE TABLE `\([^`]*\)`.*/\1/p' || true)"
DUMPED_TABLES="$(printf '%s\n' "$DUMPED_TABLE_LIST" | grep -c . || true)"
if [[ "$DUMPED_TABLES" -ne "$LIVE_TABLES" ]]; then
    verify_fail "table count mismatch: dump has ${DUMPED_TABLES}, live database has ${LIVE_TABLES}"
fi

for table in "${REQUIRED_TABLES[@]}"; do
    if ! grep -qx "$table" <<<"$DUMPED_TABLE_LIST"; then
        verify_fail "dump is missing required table '${table}'"
    fi
done

LAST_LINE="$(gzip -dc "$TARGET" | tail -n 1)"
if [[ "$LAST_LINE" != *"Dump completed"* ]]; then
    verify_fail "dump did not finish cleanly (last line: ${LAST_LINE})"
fi

log "Dump verified: ${FILENAME} (${SIZE_KB} KB, ${DUMPED_TABLES} tables)"

# --- Offsite copy ----------------------------------------------------------

if rclone listremotes 2>/dev/null | grep -qx "${RCLONE_REMOTE_NAME}:"; then
    # rclone's shared Google client_id stops working during 2026. Without a
    # personal client_id, uploads will break with no change on this server.
    if ! rclone config show "${RCLONE_REMOTE_NAME}" 2>/dev/null \
        | grep -qE '^client_id[[:space:]]*=[[:space:]]*[^[:space:]]'; then
        log "WARN: '${RCLONE_REMOTE_NAME}' uses rclone's shared client_id, which stops working during 2026. Create your own: https://rclone.org/drive/#making-your-own-client-id"
    fi

    DAILY_REMOTE="${RCLONE_REMOTE}/daily"

    if ! rclone copyto "$TARGET" "${DAILY_REMOTE}/${FILENAME}" --log-level ERROR; then
        die "upload to ${DAILY_REMOTE} failed (local dump kept)"
    fi

    log "Uploaded to ${DAILY_REMOTE}/${FILENAME}"

    # Keep one archive per month, taken from the first day of the month, for a
    # year. Daily copies age out much sooner.
    if [[ "$(date -u +%d)" == "01" ]]; then
        if rclone copyto "$TARGET" "${RCLONE_REMOTE}/monthly/${FILENAME}" --log-level ERROR; then
            log "Archived monthly copy"
        else
            log "WARN: monthly archive upload failed"
        fi
    fi

    rclone delete "$DAILY_REMOTE" \
        --min-age "${REMOTE_RETENTION_DAYS}d" \
        --include "*.sql.gz" --log-level ERROR \
        || log "WARN: pruning remote daily copies failed"

    rclone delete "${RCLONE_REMOTE}/monthly" \
        --min-age "${MONTHLY_RETENTION_DAYS}d" \
        --include "*.sql.gz" --log-level ERROR \
        || log "WARN: pruning remote monthly copies failed"
else
    log "WARN: rclone remote '${RCLONE_REMOTE_NAME}:' is not configured; backup kept locally only"
fi

# --- Local retention -------------------------------------------------------
# Local copies are cheap, but the disk is not the right place for history.

find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz" \
    -mtime +"${LOCAL_RETENTION_DAYS}" -delete
find "$BACKUP_DIR" -maxdepth 1 -type f -name "*.partial" -mtime +1 -delete

log "Backup complete: ${FILENAME}"
