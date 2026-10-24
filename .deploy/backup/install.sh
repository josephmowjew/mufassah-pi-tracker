#!/usr/bin/env bash
#
# Installs the Mufasah database backup jobs on the application server.
#
#   sudo .deploy/backup/install.sh
#
# Installs two scripts into /usr/local/bin, a cron entry running the backup
# nightly at 00:30 UTC (02:30 Malawi time) and the restore check weekly, plus
# log rotation. Safe to re-run: it overwrites the scripts and cron file.

set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $EUID -ne 0 ]]; then
    echo "Run this with sudo." >&2
    exit 1
fi

install -o root -g root -m 755 "$here/mufasah-db-backup.sh" /usr/local/bin/mufasah-db-backup.sh
install -o root -g root -m 755 "$here/mufasah-db-restore-test.sh" /usr/local/bin/mufasah-db-restore-test.sh

install -d -o root -g root -m 700 /var/backups/mufasah
install -d -o root -g root -m 700 /var/backups/mufasah/db
install -d -o root -g root -m 750 /var/log/mufasah

cat >/etc/cron.d/mufasah-backup <<'CRON'
# MariaDB backup jobs for the Mufasah Device Tracking application.
# Times are UTC; the server runs on UTC and Malawi is UTC+2.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# Nightly consistent dump, verified, uploaded to Google Drive, old copies pruned.
30 0 * * * root /usr/local/bin/mufasah-db-backup.sh >>/var/log/mufasah/backup.log 2>&1

# Weekly proof that the newest backup actually restores.
0 3 * * 0 root /usr/local/bin/mufasah-db-restore-test.sh >>/var/log/mufasah/restore-test.log 2>&1
CRON
chmod 644 /etc/cron.d/mufasah-backup

cat >/etc/logrotate.d/mufasah-backup <<'LOGROTATE'
/var/log/mufasah/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0600 root root
}
LOGROTATE
chmod 644 /etc/logrotate.d/mufasah-backup

echo "Installed:"
echo "  /usr/local/bin/mufasah-db-backup.sh"
echo "  /usr/local/bin/mufasah-db-restore-test.sh"
echo "  /etc/cron.d/mufasah-backup"
echo "  /etc/logrotate.d/mufasah-backup"
echo
echo "Run a first backup now with: sudo /usr/local/bin/mufasah-db-backup.sh"
