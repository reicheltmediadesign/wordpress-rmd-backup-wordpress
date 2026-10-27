# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.1.0] – 2026-10-27

### Added

- Backups as one zip per run with files, `database.sql.gz` and `backup-info.json`.
- Profiles with the parts database, WordPress core, `wp-config.php`, plugins, themes, uploads and rest of `wp-content`, exclusion rules in `.gitignore` syntax and database options. Five profiles are prepared on activation.
- Hourly, daily, weekly and monthly schedules per profile via WP-Cron.
- Retention per profile by number and age.
- Log file for every run, run history, log viewer and automatic log cleanup.
- Email notification per profile (never, after problems, always) with the log attached.
- Resumable runs in short steps that continue in the background via loopback requests, with WP-Cron as fallback.
- Appending zip writer with Zip64 support, so large sites are not rewritten on every step.
- Domain of the site at the start of archive and log names (setting, on by default).
- WP-CLI commands `run`, `resume`, `cancel`, `profiles`, `list`.
- Site Health checks for the backup folder and scheduled runs.
- Automatic updates from GitHub releases.
