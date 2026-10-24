# Database backups

Nightly MariaDB dumps of `mufasah_tracking`, verified, uploaded to Google Drive,
and pruned. A weekly job restores the newest dump into a scratch database to
prove it is usable.

## What runs

| Time (UTC) | Time (Malawi) | Job |
|---|---|---|
| 00:30 daily | 02:30 | `mufasah-db-backup.sh` |
| 03:00 Sunday | 05:00 | `mufasah-db-restore-test.sh` |

Cron: `/etc/cron.d/mufasah-backup`
Logs: `/var/log/mufasah/backup.log`, `/var/log/mufasah/restore-test.log`
Local copies: `/var/backups/mufasah/db` (root only, 14 days)
Offsite: `gdrive:MufasahBackups/db/daily` (90 days) and `.../monthly` (1 year)

Each run:

1. `mariadb-dump --single-transaction` (no table locking, no downtime).
2. Verify: non-trivial size, valid gzip, table count matches the live database,
   required tables present, dump ends with `Dump completed`.
3. Upload to Google Drive via rclone.
4. Prune old local and remote copies.

If any step fails the script exits non-zero and the log records why. A failed
upload keeps the local dump.

## One-time Google Drive setup

### 1. Create your own OAuth client ID (required)

rclone's shared Google client ID is being retired during 2026, so a personal
one is required. In the [Google Cloud Console](https://console.cloud.google.com/):

1. Create a project (for example `mufasah-backups`).
2. **APIs & Services → Library**, search for **Google Drive API**, enable it.
3. **OAuth consent screen / Google Auth Platform**: application name
   `Mufasah Backups`, user support email = your email, Audience = **External**.
4. **Data Access**: add the scope `.../auth/drive` (rclone needs full access
   to manage the backup folders).
5. **Audience → Add users**: add your own Google account as a test user.
6. **Audience → Publish app**. This matters: while an app is in "Testing",
   Google expires its sign-in after 7 days, which would break nightly backups
   every week. Personal use does not require verification; you will just see a
   "Google hasn't verified this app" warning once, where you click
   **Advanced → Go to (unsafe)**.
7. **Clients → Create client**, application type **Desktop app**. Copy the
   Client ID and Client Secret.

### 2. Authorise the server

Run this from your own machine, using an SSH tunnel so the browser callback
reaches the server:

```bash
ssh -L 53682:localhost:53682 -i .deploy/server.key ubuntu@84.8.147.169
sudo rclone config
```

Answers:

```
n) New remote
name> gdrive
Storage> drive            (search for "drive")
client_id>                (paste the Client ID from the console)
client_secret>            (paste the Client Secret)
scope> 1                  (full access) or 3 (drive.file, only files rclone creates)
root_folder_id>           (blank)
service_account_file>     (blank)
Edit advanced config?> n
Use web browser to automatically authenticate rclone?> y
```

Open the printed `http://127.0.0.1:53682/...` URL in the browser on your Mac
(the tunnel forwards it to the server). Approve access, then confirm `y` to
save. Quit with `q`.

### 3. Adding a client ID to an existing remote

A remote created with a blank client ID keeps working only until rclone's
shared ID is switched off. Upgrade it in place, with the SSH tunnel open:

```bash
sudo rclone config update gdrive client_id=YOUR_ID client_secret=YOUR_SECRET
sudo rclone config reconnect gdrive:
```

Answer `y` to "Already have a token - refresh?" and complete the browser flow.
The token must be refreshed, otherwise rclone keeps using the old client.

Verify:

```bash
sudo rclone lsd gdrive:
sudo rclone mkdir gdrive:MufasahBackups/db/daily
sudo rclone mkdir gdrive:MufasahBackups/db/monthly
sudo /usr/local/bin/mufasah-db-backup.sh
sudo rclone ls gdrive:MufasahBackups/db/daily
```

## Restoring a backup

Copy the dump you want to restore, then restore into a **new** database and
point the application at it, rather than overwriting the live one:

```bash
# Download the newest offsite copy first, if the local one is gone
rclone copy gdrive:MufasahBackups/db/daily /var/backups/mufasah/db --include "*.sql.gz"

# Restore into a scratch database
sudo mariadb -e "CREATE DATABASE mufasah_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo bash -c 'gzip -dc /var/backups/mufasah/db/<file>.sql.gz | mariadb mufasah_restore'

# Sanity check
sudo mariadb -e "SELECT COUNT(*) FROM mufasah_restore.users;"
```

To replace the live database, stop writes first (maintenance mode), take an
extra dump of the current state, then restore over `mufasah_tracking`.

## Manual commands

```bash
sudo /usr/local/bin/mufasah-db-backup.sh          # run a backup now
sudo /usr/local/bin/mufasah-db-restore-test.sh    # verify the newest dump now
tail -n 50 /var/log/mufasah/backup.log
rclone ls gdrive:MufasahBackups/db/daily
rclone ls gdrive:MufasahBackups/db/monthly
```

## Adjusting retention

Edit the environment defaults at the top of
`/usr/local/bin/mufasah-db-backup.sh`:

- `LOCAL_RETENTION_DAYS` (default 14)
- `REMOTE_RETENTION_DAYS` (default 90)
- `MONTHLY_RETENTION_DAYS` (default 365)

Re-run `install.sh` from the repository after changing the repo copy, so the
installed script and the repo stay in step.
