#!/usr/bin/env bash
# Builds the scripts, then writes the plugin's .pot with WP-CLI in wp-env.
# Usage: bin/make-pot.sh <output path relative to the plugin>
set -euo pipefail

cd "$(dirname "$0")/.."

out="${1:-languages/showfm.pot}"
version="$(sed -n 's/^ \* Version: *//p' showfm.php)"

npm run build
mkdir -p "$(dirname "$out")"
npx wp-env run cli --env-cwd=wp-content/plugins/showfm wp i18n make-pot . "$out" \
	--slug=showfm --domain=showfm \
	--exclude=node_modules,vendor,tests,dist,src,artifacts,assets/showfm-embed,scripts,bin,.playwright-mcp,.wordpress-org \
	--headers="{\"Report-Msgid-Bugs-To\":\"https://github.com/ShowDotFM/showfm-wordpress/issues\",\"Project-Id-Version\":\"show.fm Podcast Player $version\"}"
