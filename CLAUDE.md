# RMD Backup WordPress – Projektregeln

WordPress-Plugin von reichelt media.design: schlankes Backup (Dateien + Datenbank in einem Zip) mit Profilen, Zeitplänen, Aufbewahrung, Logs und E-Mail. Ersatz für BackWPup auf Kundenseiten. Repo: `github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress`, Lizenz GPL-2.0-or-later, Autor Philipp Reichelt. Aufbau und Release wie `rmd-migrate-from-localdev` (ohne npm/Build-Schritt).

## Aufbau

- `rmd-backup-wordpress.php` Bootstrap (muss PHP-7-parsebar bleiben), `includes/` PSR-4 `RMD\BackupWordpress\`.
- `includes/Domain/` ist **WordPress-frei** und wird mit plain PHPUnit getestet: `Profile` (einzige Stelle für die Datenform eines Profils), `Layout` (welche Ordner zu welchem Teil gehören, Namen im Archiv), `PathFilter` (.gitignore-Teilmenge), `Schedule` (nächster Termin), `Retention`, `ArchiveName` (Lauf-IDs, validiert jeden Dateinamen aus Requests), `Compression`, `Sql`, `ZipWriter`, `Bech32` + `Age` (age-Format v1, X25519, STREAM in 64-KiB-Chunks).
- `ZipWriter` statt `ZipArchive`: ZipArchive schreibt beim Wiederöffnen das ganze Archiv neu. Der Writer hängt nur an (Central Directory in Seitendatei, Zip64-fähig) und wird über `state()` + Konstruktor fortgesetzt. Änderungen immer mit `tests/Unit/ZipWriterTest.php` prüfen (liest mit ZipArchive gegen).
- `includes/Backup/`: `Job` (Zustand in Option `rmd_bwp_job`, Phasen database → scan → archive → encrypt → finalize → upload; Lock als Zeile `rmd_bwp_lock` per `INSERT IGNORE`, abgebrochene Schritte werden wiederholt, nach 3 Versuchen Fehler), `DatabaseDump` + `DumpFile` (pro Schritt ein Gzip-Member), `FileScanner` (Manifest `files.txt`), `Archiver`, `Runner` (Cron-Events `rmd_bwp_scheduled` / `rmd_bwp_continue`, Loopback auf `admin-ajax.php?action=rmd_bwp_continue` mit Schlüssel `rmd_bwp_key`).
- Aufbewahrung getrennt: `keep_count`/`keep_days` für die Website (Standard 3), `remote_keep_count`/`remote_keep_days` für das SFTP-Ziel (Standard 7, Puffer fürs NAS). `Upgrade` (Option `rmd_bwp_version`) führt einmalige Datenänderungen nach Updates aus; neue Schritte dort mit Versionsvergleich ergänzen.
- Ausgabedateien werden beim Fortsetzen auf die im Zustand gespeicherte Länge gekürzt; Zustand wird nur am Schrittende gespeichert.
- Verschlüsselung: Änderungen an `Age` immer gegen das offizielle `age`-Tool prüfen (age-keygen, mit PHP verschlüsseln, `age -d`). Nur der öffentliche Schlüssel liegt auf der Website; Post-Quanten-Schlüssel (`age1pq…`) bewusst nicht unterstützt (kein natives ML-KEM in PHP, keine eigene Implementierung). Braucht die Erweiterung sodium (Polyfill von WP ist ~0,3 MB/s und wird abgelehnt); lokale Tests mit `php -d extension=sodium`.
- Ziele: `Destination` (label, backups, delete), `LocalDestination` (+ store), `RemoteDestination` (+ is_configured, upload in Schritten). `SftpDestination` (phpseclib 3) mit gepinntem Host-Key, Upload nach `….part` + Rename, `SftpSettings` (Option `rmd_bwp_sftp`, Konstanten `RMD_BWP_SFTP_*`, Anmeldung `auth` = `key` (Standard) oder `password`; privater Schlüssel und Passwort via `Secret` mit wp_salt verschlüsselt). `Domain\SshKey` erzeugt pro Website ein ed25519-Paar (Kommentar `rmd-backup <domain>`); HiDrive nimmt beliebig viele Schlüssel pro Konto an (getestet), daher ein Schlüssel pro Kundenseite und 2FA für das Backup-Konto. Installationen aus 0.1.0 mit Passwort bleiben bei `password`, bis umgestellt wird. Uploads nur für verschlüsselte Archive (Job erzwingt das).
- `Log\Logger` (eine Datei pro Lauf, Englisch), `Log\LogStore` (Historie in `rmd_bwp_runs`, max. 50 Einträge – keine großen Optionen, siehe BackWPup-Timeout in `rmd-wordpress.git/knowledge/debugging/`).
- Ablage `wp-content/rmd-backup-wordpress/{backups,logs,tmp}`, überschreibbar per `RMD_BWP_STORAGE_DIR`. Der Ordner ist immer ausgeschlossen; `rmd_bwp_job`/`_lock`/`_cancel` fehlen im Dump.
- `Rest\BackupController` (Admin treibt manuelle Läufe), `Admin\Page` (Werkzeuge → Backups, Tabs) mit `assets/js/admin.js` (Vanilla, `wp-api-fetch`), `Cli` (`wp rmd-backup …`), `SiteHealth`, `Notifier`.
- Zugriff: `manage_options`; jede Schreibaktion mit Nonce. Kein Restore (bewusst; README beschreibt den manuellen Weg).

## Code-Regeln

- PHP 8.1+, WP 6.8+. WordPress-Coding-Standards (`phpcs.xml.dist`), Methoden snake_case, Präfix `rmd_bwp_` / `RMD_BWP_` / Namespace `RMD\BackupWordpress`, Textdomain `rmd-backup-wordpress`.
- Quellstrings Englisch, Übersetzung in `languages/rmd-backup-wordpress-de_DE.po` von Hand nachziehen (`bash bin/make-i18n.sh` braucht WP-CLI; lokal nicht installiert, im Release-Workflow vorhanden). Log-Zeilen bleiben Englisch und unübersetzt.
- Version an drei Stellen: Plugin-Header, `RMD_BWP_VERSION`, `readme.txt` (Stable tag). `bash bin/check-version.sh` prüft das.

## Build und Prüfung

```powershell
composer phpcs         # PHPCS
composer test          # PHPUnit (Domain)
bash bin/check-version.sh
bash bin/build-zip.sh  # dist/rmd-backup-wordpress.zip (Git Bash: zip, composer nötig)
```

Lokal testen: `.\bin\link-local.ps1 -WordPressPath "C:\xampp\htdocs\testwordpress"` legt eine Junction in `wp-content\plugins` an. Läufe lassen sich ohne WP-CLI per PHP-Skript treiben, das `wp-load.php` lädt und `Job::start()` / `Job::step()` aufruft. Kundenseiten bekommen nur das Release-Zip.

## Git und Release

- Committen nur auf Anfrage, auf Feature-Branches; nie direkt auf `main`.
- Release: Versionen an allen Stellen erhöhen, `CHANGELOG.md` und `readme.txt` (Changelog) ergänzen, committen, Tag `vX.Y.Z` pushen. Der Workflow lintet, testet, baut Übersetzungen, erzeugt `rmd-backup-wordpress.zip` und `update.json` und legt ein **Draft-Release** an. Beide Assets müssen am Release bleiben (Update-Check liest `releases/latest/download/update.json`).
- Den vom Workflow angelegten Entwurf bearbeiten (Releases → Entwurf → Stift), nicht neu anlegen.
- Bei jedem Release Titel `vX.Y.Z – <Kernänderung>` und Release Notes (Englisch, Markdown in Codeblock) mit ausgeben; Grundlage `git log <letzter Tag>..HEAD`.
- `gh` ist installiert (ggf. noch mit vollem Pfad `C:\Program Files\GitHub CLI\gh.exe` aufrufen); Workflow-Status alternativ über `https://api.github.com/repos/reicheltmediadesign/wordpress-rmd-backup-wordpress/actions/runs`.
- Topics fürs Repo: `wordpress`, `wordpress-plugin`, `backup`, `cron`, `zip`.

## README

Nutzerorientiert (Features, Usage, Settings, Privacy, License). Kein Development-Abschnitt in der README; Build/Release steht hier.
