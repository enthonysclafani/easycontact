#!/usr/bin/env bash
# Builds the distributable zip from the committed tree (HEAD).
# Dev-only files are excluded through `export-ignore` in .gitattributes: tests, tools,
# docs and every development config stay out of the plugin installed on a site.
set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p .dist
rm -f .dist/easyformly.zip
git archive --format=zip --prefix=easyformly/ -o .dist/easyformly.zip HEAD
echo "Built .dist/easyformly.zip"
