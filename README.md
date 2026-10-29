# RMD Backup WordPress

Lightweight backup plugin for WordPress. Every run creates **one zip** with the files and the database of the site, either manually or on a schedule. Old backups and logs are cleaned up automatically, and every run writes a log. Profiles decide what is backed up. Built by [reichelt media.design](https://reicheltmedia.design) as a lean alternative to large backup suites.

## Features

- **One zip per run** with the files, the database dump (`database.sql.gz`) and `backup-info.json` (site address, WordPress, PHP and database version, table prefix, profile).
- **Profiles** for what to back up. Prepared on activation: *Full backup*, *Database only*, *Files only*, *Content only (wp-content)* and *Uploads only*. Each profile combines the parts database, WordPress core, `wp-config.php`, plugins, themes, uploads and the rest of `wp-content`, with its own exclusion rules in `.gitignore` syntax.
- **Schedules** per profile: hourly, daily, weekly or monthly at a set time in the site's time zone, or only manually.
- **Retention** per profile: keep the newest *n* backups and/or backups younger than *n* days. The newest backup is never deleted.
- **Log for every run** with environment, tables, file counts, warnings, errors and duration. Viewable and downloadable in the admin; old logs are deleted automatically.
- **Encryption** with [age](https://age-encryption.org): only your public key is stored on the site, so neither a compromised site nor a leaked off-site copy can read the backups. Decrypt with the age command line tool and your private key.
- **Off-site copy via SFTP** (e.g. STRATO HiDrive): encrypted backups are uploaded in resumable chunks to a folder per site. Every site logs in with its own SSH key (works with HiDrive two-factor authentication), the server's host key is pinned, and the retention rules apply there as well.
- **Email notification** per profile: never, only after warnings or errors, or after every run. The log is attached.
- **Robust on shared hosting:** a run is split into short steps and continues in the background, so PHP time limits do not matter. An interrupted step is repeated from the last saved state; the archive is written with an appending zip writer instead of being rewritten on every step. Large files and formats that are compressed already (images, videos, archives) are stored without recompression.
- **Little overhead:** no external services besides the SFTP server you configure, no large serialized options (the run history is capped at 50 entries), nothing loaded on the front end.
- **Protected storage** in `wp-content/rmd-backup-wordpress/` with deny rules for Apache and IIS and a random part in every file name.
- **WP-CLI** commands for reliable runs from a server cron job.
- **Site Health** checks for the backup folder, free disk space and overdue or failed scheduled runs.

## Requirements

WordPress 6.8 or newer, PHP 8.1 or newer, a single site (no multisite). The zlib extension is recommended (compressed database dump); without it the dump is stored uncompressed. Encryption needs the sodium extension, which is active on almost every host.

## Installation

1. Download `rmd-backup-wordpress.zip` from the latest [release](https://github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress/releases). **Do not use the “Source code” downloads**, they lack the update library.
2. Go to Plugins → Add New → Upload Plugin, choose the zip and activate it.
3. Open **Tools → Backups**.

## Usage

- **Back up now:** choose a profile on the *Backups* tab and click **Back up now**. The progress bar shows the current table or file count. You can leave the page; the backup continues in the background.
- **Download or delete** stored backups in the list below.
- **Schedules, retention and notifications** are set per profile on the *Profiles* tab.
- **Logs** of all runs are on the *Logs* tab.

### Files

| Folder | Content |
| --- | --- |
| `wp-content/rmd-backup-wordpress/backups/` | The archives, e.g. `2026-10-27_030000_example.com_full_a1b2c3.zip`, encrypted `….zip.age` |
| `wp-content/rmd-backup-wordpress/logs/` | One log per run, named like the archive |
| `wp-content/rmd-backup-wordpress/tmp/` | Work files of the running backup, removed afterwards |

To keep backups outside the web root, define the folder in `wp-config.php`:

```php
define( 'RMD_BWP_STORAGE_DIR', '/home/example/backups/wordpress' );
```

### Reliable schedules

Scheduled backups use WP-Cron, which only runs when the site has visitors. For exact times disable WP-Cron in `wp-config.php` and call it from a server cron job (e.g. in Plesk under *Scheduled Tasks*):

```php
define( 'DISABLE_WP_CRON', true );
```

```
*/5 * * * * wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Or run backups directly with WP-CLI, which works in one process without time limits:

```
wp rmd-backup run full
```

| Command | Description |
| --- | --- |
| `wp rmd-backup run <profile>` | Runs a backup with the profile. |
| `wp rmd-backup resume` | Continues the current backup. |
| `wp rmd-backup cancel` | Cancels the current backup. |
| `wp rmd-backup profiles` | Lists the profiles with their ids. |
| `wp rmd-backup list` | Lists the stored backups. |

### Encryption and off-site copies (HiDrive)

1. **Create a key pair** once on your own computer with [age](https://github.com/FiloSottile/age/releases):

   ```
   age-keygen -o backup-key.txt
   ```

   The output shows the public key (`age1…`). Keep `backup-key.txt` (the private key) in your password manager and a second safe place: without it, encrypted backups cannot be restored. Post-quantum keys (`age-keygen -pq`, `age1pq…`) are not supported yet.
2. **Enter the public key** under *Settings → Encryption*, or better define it in `wp-config.php`, where it cannot be changed from the admin screen:

   ```php
   define( 'RMD_BWP_AGE_RECIPIENT', 'age1…' );
   ```

3. **Create an SFTP account** that only reaches its own folder and has no other protocols (HiDrive: an extra user with SFTP only).
4. Under *Settings → Upload to SFTP* enter server and user, keep **Login: SSH key** and click **Create key pair**. The private key is created on the site and never leaves it.
5. Click **Download public key** (`rmd-backup_<domain>.pub`; the key comment is `rmd-backup <domain>`) and add the file to the account. HiDrive: log in as owner, *Settings → Account →* the backup account *→ Open-SSH → SSH Schlüssel hinterlegen*. An account can hold many keys, one per site.
6. Click **Test connection** (this stores the server's host key) and enable **Upload to SFTP** in the profiles.
7. HiDrive: once all sites use keys, enable two-factor authentication for the backup account and delete its protocol passwords. Key logins keep working with 2FA; password logins would need the current code and fail in scheduled runs.

To lock out a site (compromised or no longer a customer), delete only its key in the account. Every other site keeps working. **Create new key pair** replaces the key of a site; remove the old public key from the account afterwards.

Every site uploads into its own folder (default: its domain). Each upload goes to `….part` first and is renamed when complete. If the server presents a different host key, the upload stops.

The SFTP settings can also be set in `wp-config.php`: `RMD_BWP_SFTP_HOST`, `RMD_BWP_SFTP_PORT`, `RMD_BWP_SFTP_USER`, `RMD_BWP_SFTP_AUTH` (`key` or `password`), `RMD_BWP_SFTP_PRIVATE_KEY` (private key without passphrase), `RMD_BWP_SFTP_PASSWORD`, `RMD_BWP_SFTP_PATH`, `RMD_BWP_SFTP_FOLDER`, `RMD_BWP_SFTP_FINGERPRINT`.

Since one SFTP account serves all sites, a compromised site could delete or overwrite the archives in that account (but not read them). Keep a second copy that sites cannot reach, e.g. a Synology NAS that only downloads from the account (Cloud Sync, "download remote changes only", without deleting) and keeps snapshots.

### Restoring a backup

The plugin does not restore backups; do it by hand:

1. Decrypt an encrypted backup: `age -d -i backup-key.txt -o backup.zip backup.zip.age`. Then unpack the zip.
2. **Files:** upload the contents into the WordPress folder (SFTP or the file manager of your hosting panel). `wp-config.php` is in the zip if the profile includes it; keep the existing one if the database credentials changed.
3. **Database:** import `database.sql.gz` in phpMyAdmin (Import tab; it reads `.gz` directly) or with `gunzip < database.sql.gz | mysql -u USER -p DATABASE`. Existing tables with the same names are replaced.
4. Log in, save **Settings → Permalinks** once and clear caches.

## Settings

### Profile

| Setting | Default | Description |
| --- | --- | --- |
| Name | – | Shown in lists, logs and emails. |
| Back up | all parts | Database; WordPress core (`wp-admin`, `wp-includes`, files in the main folder); `wp-config.php` (also found one level above the WordPress folder); plugins; themes; uploads; rest of `wp-content` (`mu-plugins`, `languages`, …). |
| Leave out | see below | Exclusion rules, one per line. |
| Other tables | off | Also back up tables without the table prefix of this site. |
| Leave out tables | empty | Table names with or without prefix. |
| Transients | on | Leave out transients (temporary cache entries). |
| Run | Only manually | Hourly, daily, weekly or monthly. |
| Time | 03:00 | In the site's time zone; for hourly runs only the minute counts. |
| Day of the week / month | Monday / 1 | For weekly / monthly runs (day 1–28). |
| Keep backups | 7 | Newest backups of this profile to keep. 0 = no limit. |
| Keep for days | 0 | Delete older backups of this profile. 0 = no limit. |
| Upload to SFTP | off | Also upload the encrypted backup to the SFTP server. Needs encryption and a tested connection. Retention applies there too. |
| Send email | Only after warnings or errors | Never, only after warnings or errors, or after every run. |
| Recipient | empty | Empty = default recipient from the settings, otherwise the administration email address. |

### Exclusion rules

The syntax follows `.gitignore` and is matched against the path in the archive: `name` matches a file or folder at any depth, `/name` only directly in the WordPress folder, a trailing `/` only folders, `*` and `?` stay within a path segment, `**` spans segments. Matching is case-insensitive. Defaults:

```
.git/
.svn/
node_modules/
/wp-content/cache/
/wp-content/upgrade/
/wp-content/upgrade-temp-backup/
/wp-content/ai1wm-backups/
/wp-content/updraft/
/wp-content/backups-dup-lite/
/wp-content/wpvividbackups/
/wp-content/uploads/backwpup-*/
*.log
.DS_Store
Thumbs.db
```

The backup folder of this plugin is always left out.

### General

| Setting | Default | Description |
| --- | --- | --- |
| File name | on | Include the domain of the site in file names (`2026-10-27_030000_example.com_full_…`, for sites in a subfolder `example.com-blog`). |
| Keep logs | 100 | Number of newest logs to keep. 0 = no limit. |
| Keep logs for days | 90 | 0 = no limit. |
| Default recipient | empty | For profiles without their own recipient. Empty = administration email address. |
| Public key | empty | age public key (`age1…`); empty = no encryption. `RMD_BWP_AGE_RECIPIENT` takes precedence. |

### Upload to SFTP

| Setting | Default | Description |
| --- | --- | --- |
| Server / Port | `sftp.hidrive.strato.com` / 22 | |
| User name | – | The backup account. |
| Login | SSH key | **SSH key** (recommended, works with HiDrive 2FA) or **Password** for servers without key login. Sites upgraded from 0.1.0 with a stored password keep using it until you switch. |
| SSH key of this site | – | **Create key pair**, **Download public key**, fingerprints (SHA256 and MD5) to compare with the key list of the account. Stored encrypted with the security keys of `wp-config.php`. |
| Password | – | Only for the password login. Stored encrypted with the security keys of `wp-config.php`. |
| Base folder | empty | Empty = the own folder of the account (HiDrive logs in at `/` and uses `/users/<name>`), otherwise the folder after login. Relative paths start at the folder after login. |
| Folder for this site | domain of the site | Letters, digits, dots, hyphens, underscores. |
| Host key | – | Stored by **Test connection**; every upload must see the same key. **Reset host key** forgets it. |

## Styling

The admin screen uses the classes `.rmd-bwp`, `.rmd-bwp-progress` (with `data-status="running|success|warning|error|cancelled"`), `.rmd-bwp-table`, `.rmd-bwp-badge-<status>` and `.rmd-bwp-log`.

## Privacy

The plugin sends no data to external services except the encrypted backups to the SFTP server you configure. It makes requests to its own site (`admin-ajax.php`, authorised by a random key) to continue a backup in the background, and checks GitHub for plugin updates from the WordPress admin. Emails go to the addresses set in the profiles or settings.

Backups contain the complete database including user accounts and password hashes, and with `wp-config.php` the database credentials. Download them only over HTTPS, keep copies private and do not keep more than needed. On nginx the deny rules of the backup folder do not apply; the random part of the file names keeps the archives from being guessed, but a folder outside the web root (`RMD_BWP_STORAGE_DIR`) is safer.

Deleting the plugin removes its settings, profiles and history; the backup folder is kept and must be deleted by hand.

## License

GPL-2.0-or-later. Uses [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (MIT).
