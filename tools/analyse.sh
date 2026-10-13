#!/usr/bin/env bash
# Runs PHPStan from its isolated install in tools/phpstan (see tools/phpstan/composer.json).
#
# Locally and in CI it installs PHPStan on first use. In Claude Code cloud sessions PHPStan
# cannot be downloaded (GitHub proxy), so it says so explicitly and leaves the check to the
# CI run on the pull request instead of failing every `composer check`.
set -euo pipefail

cd "$(dirname "$0")/.."
bin="tools/phpstan/vendor/bin/phpstan"

if [ ! -x "$bin" ]; then
	if [ "${CLAUDE_CODE_REMOTE:-}" = "true" ]; then
		echo "PHPStan non disponibile in questo ambiente cloud: lo verifica la CI sulla pull request." >&2
		exit 0
	fi
	composer install --working-dir=tools/phpstan --no-interaction --no-progress
fi

exec "$bin" analyse --memory-limit=1G --no-progress "$@"
