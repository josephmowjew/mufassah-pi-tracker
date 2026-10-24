#!/usr/bin/env bash
#
# Weekly restore verification for the Mufasah Device Tracking database.
#
# Takes the newest local backup, restores it into a scratch database, checks
# that the schema and key data arrived intact, then drops the scratch database.
# This is what turns "we take backups" into "we can restore".
#
# Installed to /usr/local/bin/mufasah-db-restore-test.sh and run weekly from
# /etc/cron.d/mufasah-backup.

set -Eeuo pipefail

DB_NAME="${DB_NAME:-mufasah_tracking}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/mufasah/db}"
TEST_DB="${TEST_DB:-mufasah_restore_test}"
KEY_TABLES=(users branches devices inventory_batches sales migrations)

log() { printf '%s %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*"; }
die() { log "ERROR: $*"; exit 1; }

cleanup() {
    mariadb -e "DROP DATABASE IF EXISTS \`${TEST_DB}\`;" 2>/dev/null || true
}
trap cleanup EXIT

LATEST="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz" \
    -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -n 1 | cut -d' ' -f2- || true)"
[[ -n "${LATEST:-}" ]] || die "no backup found in ${BACKUP_DIR}"

log "Verifying ${LATEST}"

gzip -t "$LATEST" || die "backup is not valid gzip"

mariadb -e "DROP DATABASE IF EXISTS \`${TEST_DB}\`;
            CREATE DATABASE \`${TEST_DB}\`
            CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    || die "could not create scratch database ${TEST_DB}"

if ! gzip -dc "$LATEST" | mariadb "${TEST_DB}"; then
    die "restore of ${LATEST} failed"
fi

RESTORED_TABLES="$(mariadb -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = '${TEST_DB}' AND table_type = 'BASE TABLE'")"

EXPECTED_TABLES="$(gzip -dc "$LATEST" | grep -c '^CREATE TABLE ' || true)"
if [[ "$RESTORED_TABLES" -ne "$EXPECTED_TABLES" ]]; then
    die "restored ${RESTORED_TABLES} tables, dump defines ${EXPECTED_TABLES}"
fi

for table in "${KEY_TABLES[@]}"; do
    COUNT="$(mariadb -N -B -e "SELECT COUNT(*) FROM \`${TEST_DB}\`.\`${table}\`")" \
        || die "could not read restored table ${table}"

    if ((COUNT == 0)); then
        die "restored table '${table}' is empty"
    fi
done

CHECK_OUTPUT="$(mariadb-check "${TEST_DB}" 2>&1 || true)"
if printf '%s' "$CHECK_OUTPUT" | grep -qiE 'error|corrupt|not closed|doesn.t exist'; then
    die "mariadb-check reported problems: ${CHECK_OUTPUT}"
fi

log "Restore verified: ${RESTORED_TABLES} tables, key tables populated, mariadb-check clean"
