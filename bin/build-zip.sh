#!/usr/bin/env bash
# Builds dist/rmd-backup-wordpress.zip with a single top-level folder named after
# the plugin slug (required for in-place updates). Used locally and in CI.
set -euo pipefail

cd "$(dirname "$0")/.."

slug="rmd-backup-wordpress"
stage="dist/$slug"
composer_bin="$(command -v composer || command -v composer.bat)"   # Git Bash on Windows only finds composer.bat.

rm -rf dist
mkdir -p "$stage"

# Copy everything except what .distignore lists (tar is available on Git Bash and Linux alike).
tar --exclude-from=.distignore -cf - . | tar -xf - -C "$stage"

# Production vendor folder inside the package (composer files are removed again afterwards).
cp composer.json composer.lock "$stage/"
(
	cd "$stage"
	"$composer_bin" install --no-dev --no-interaction --no-progress --classmap-authoritative --quiet
	rm -f composer.json composer.lock
)

(
	cd dist
	rm -f "$slug.zip"
	zip -qr "$slug.zip" "$slug"
)

rm -rf "$stage"
echo "Created dist/$slug.zip"
unzip -l "dist/$slug.zip" | head -n 12
