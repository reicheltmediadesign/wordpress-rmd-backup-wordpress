#!/usr/bin/env bash
# Regenerates the POT file, updates the German PO and compiles MO/PHP
# files. Requires WP-CLI (wp) with the i18n command.
set -euo pipefail

cd "$(dirname "$0")/.."

wp_bin="${WP_CLI:-$(command -v wp || command -v wp.bat)}"   # Git Bash on Windows only finds wp.bat.
domain="rmd-backup-wordpress"

"$wp_bin" i18n make-pot . "languages/$domain.pot" \
	--slug="$domain" \
	--domain="$domain" \
	--exclude="node_modules,vendor,tests,dist,bin,.github" \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/reicheltmediadesign/wordpress-rmd-backup-wordpress/issues"}' \
	--package-name="RMD Backup WordPress"

if [ -f "languages/$domain-de_DE.po" ]; then
	"$wp_bin" i18n update-po "languages/$domain.pot" "languages/$domain-de_DE.po"
fi

"$wp_bin" i18n make-mo languages
"$wp_bin" i18n make-php languages

echo "Translation files updated."
ls -1 languages
