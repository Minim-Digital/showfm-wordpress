#!/usr/bin/env bash
# Builds the distribution zip in dist/: dist/showfm/ and dist/showfm-{version}.zip.
#
# Files listed in .distignore are left out. The script then fails if anything that must
# never ship (tests, dependencies, CI, AI tool directories, Markdown) is in the zip.
set -euo pipefail

cd "$(dirname "$0")/.."

slug="showfm"
version="$(sed -n 's/^ \* Version: *//p' showfm.php)"
stable_tag="$(sed -n 's/^Stable tag: *//p' readme.txt)"
constant="$(sed -n "s/^define( 'SHOWFM_VERSION', '\(.*\)' );$/\1/p" showfm.php)"

if [ -z "$version" ] || [ "$version" != "$stable_tag" ] || [ "$version" != "$constant" ]; then
	echo "Version mismatch: header '$version', SHOWFM_VERSION '$constant', readme Stable tag '$stable_tag'." >&2
	exit 1
fi

if [ -d src ]; then
	npm run build
fi

rm -rf dist
mkdir -p "dist/$slug"
rsync -a --exclude-from=.distignore ./ "dist/$slug/"

forbidden="$(cd dist && find "$slug" \( \
	-path "$slug/tests" -o -path "$slug/vendor" -o -path "$slug/node_modules" \
	-o -path "$slug/.github" -o -path "$slug/.claude" -o -path "$slug/.codex" -o -path "$slug/.agents" \
	-o -name '*.md' -o -name 'composer.*' -o -name 'package*.json' -o -name '.wp-env*.json' \
	-o -name 'phpunit*.xml*' -o -name 'phpcs.xml*' -o -name 'phpstan.neon*' \
	\) -print)"
if [ -n "$forbidden" ]; then
	echo "These must not ship in the zip:" >&2
	echo "$forbidden" >&2
	exit 1
fi

(cd dist && zip -qrX "$slug-$version.zip" "$slug")

echo "Built dist/$slug-$version.zip:"
(cd dist && unzip -Z1 "$slug-$version.zip")
