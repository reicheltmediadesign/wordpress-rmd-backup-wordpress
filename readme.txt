=== RMD Backup WordPress ===
Contributors: reicheltmediadesign
Tags: backup, database backup, scheduled backup, cron, zip
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight backups: files and database in one zip, profiles, schedules, automatic cleanup and a log for every run.

== Description ==

RMD Backup WordPress creates one zip per run with the files and the database of the site, manually or on a schedule. It is a lean alternative to large backup suites.

* Profiles for what to back up: database, WordPress core, wp-config.php, plugins, themes, uploads, rest of wp-content, with exclusion rules. Full, database-only, files-only, content-only and uploads-only profiles are prepared.
* Hourly, daily, weekly or monthly schedules per profile.
* Retention by number and age; the newest backup is always kept.
* A log for every run, viewable in the admin; old logs are deleted automatically.
* Email after errors or after every run, with the log attached.
* Encryption with age: only the public key is stored on the site.
* Off-site copy of encrypted backups via SFTP, e.g. to STRATO HiDrive, with one SSH key per site.
* Runs in short steps and continues in the background, so PHP time limits do not matter.
* WP-CLI commands and Site Health checks.

== Installation ==

1. Upload the plugin zip under Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to Tools → Backups, choose a profile and click Back up now.

== Frequently Asked Questions ==

= How do I restore a backup? =

Unpack the zip, upload the files into the WordPress folder and import database.sql.gz in phpMyAdmin. The README on GitHub describes the steps.

= Where are the backups stored? =

In wp-content/rmd-backup-wordpress/backups, protected by deny rules. Define RMD_BWP_STORAGE_DIR in wp-config.php to use a folder outside the web root.

== Changelog ==

= 0.4.0 =
* Changed: SFTP uploads go to the base folder "backups" by default (HiDrive: /users/<name>/backups/<domain>/). Move existing site folders there.

= 0.3.0 =
* New: separate retention for the SFTP server (default 7).
* Changed: sites keep 3 backups per profile by default; profiles on the old default of 7 are lowered to 3 on update.

= 0.2.0 =
* New: SSH key login for SFTP uploads, one key pair per site; works with HiDrive two-factor authentication.

= 0.1.0 =
* Initial release.
