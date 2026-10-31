# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.5.0] – 2026-10-31

### Added

- *Backups* tab: section "Scheduled backups" with every scheduled profile, its next run (soonest first, overdue runs marked), SFTP upload and last result.
- *Settings* tab: setup steps until encryption (and, with an SFTP user, a successful connection test) is set up.

### Changed

- *Settings* tab is one form with one **Save settings** button. Before, the general part and the SFTP part had their own buttons, and saving one discarded unsaved changes in the other.
- **Test connection** became **Save and test connection**; **Create key pair** and **Reset host key** save the page first, so entered values are no longer lost.
- Invalid SFTP values no longer save the valid fields of the form; nothing is saved and all errors are shown.

## [0.4.0] – 2026-10-30

### Changed

- SFTP uploads go to the base folder `backups` by default, on HiDrive `/users/<name>/backups/<domain>/`, so a sync tool can take exactly this folder.
- Relative base folders now start in the own folder of the account (`/users/<name>` on HiDrive) instead of the login folder; `.` means the own folder itself.

### Upgrading

- Sites with an empty base folder used `/users/<name>/<domain>/` until now. New backups go to `/users/<name>/backups/<domain>/`. Move the existing site folders into `backups/` on the server (e.g. in the HiDrive web interface), otherwise the old backups there are no longer cleaned up.

## [0.3.0] – 2026-10-29

### Added

- Separate retention for the SFTP server per profile (*Keep on SFTP server*, default 7 backups, and *for days*), so the off-site copy can keep more than the site.

### Changed

- Profiles keep 3 backups on the site by default instead of 7.

### Upgrading

- Profiles that still use the old default of 7 are set to 3 on the site. The SFTP server keeps 7 as before. Profiles with another number stay as they are.

## [0.2.0] – 2026-10-29

### Added

- SSH key login for the SFTP destination: every site creates its own ed25519 key pair; only the public key (comment `rmd-backup <domain>`) is added to the account, so a single site can be locked out by deleting its key. Works with HiDrive two-factor authentication. Download of the public key as `rmd-backup_<domain>.pub`, SHA256 and MD5 fingerprints.
- Settings `RMD_BWP_SFTP_AUTH` and `RMD_BWP_SFTP_PRIVATE_KEY` in `wp-config.php`.
- Site Health reports an SFTP key or password that can no longer be decrypted.

### Changed

- New SFTP setups log in with an SSH key. Sites upgraded from 0.1.0 with a stored password keep the password login until it is switched.

## [0.1.0] – 2026-10-27

### Added

- Backups as one zip per run with files, `database.sql.gz` and `backup-info.json`.
- Profiles with the parts database, WordPress core, `wp-config.php`, plugins, themes, uploads and rest of `wp-content`, exclusion rules in `.gitignore` syntax and database options. Five profiles are prepared on activation.
- Hourly, daily, weekly and monthly schedules per profile via WP-Cron.
- Retention per profile by number and age.
- Log file for every run, run history, log viewer and automatic log cleanup.
- Email notification per profile (never, after problems, always) with the log attached.
- Encryption of the archives with age (X25519 public key; `.zip.age`), resumable over several steps; `RMD_BWP_AGE_RECIPIENT` in `wp-config.php` takes precedence.
- Off-site copy via SFTP (e.g. STRATO HiDrive) per profile: only encrypted archives, resumable chunked upload to `….part`, pinned host key, retention on the server, password stored encrypted or set as constant.
- Resumable runs in short steps that continue in the background via loopback requests, with WP-Cron as fallback.
- Appending zip writer with Zip64 support, so large sites are not rewritten on every step.
- Domain of the site in archive and log names after the date (setting, on by default).
- WP-CLI commands `run`, `resume`, `cancel`, `profiles`, `list`.
- Site Health checks for the backup folder and scheduled runs.
- Automatic updates from GitHub releases.
