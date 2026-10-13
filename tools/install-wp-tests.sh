#!/usr/bin/env bash
# Installs the WordPress PHPUnit test suite for local runs of `composer test`.
#
# Usage: tools/install-wp-tests.sh [db-name] [db-user] [db-pass] [db-host] [wp-ref]
# tests/bootstrap.php finds the suite in /tmp/wordpress-develop by default.
#
# Needs a MySQL/MariaDB server. The test database is wiped on every run.
set -euo pipefail

DB_NAME="${1:-wordpress_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-}"
DB_HOST="${4:-127.0.0.1}"
WP_REF="${5:-7.1.0}"
DEST="/tmp/wordpress-develop"

if [ ! -d "$DEST" ]; then
	git clone --depth=1 --branch "$WP_REF" https://github.com/WordPress/wordpress-develop.git "$DEST"
fi

cp "$DEST/wp-tests-config-sample.php" "$DEST/wp-tests-config.php"
sed -i.bak \
	-e "s/youremptytestdbnamehere/${DB_NAME}/" \
	-e "s/yourusernamehere/${DB_USER}/" \
	-e "s/yourpasswordhere/${DB_PASS}/" \
	-e "s|localhost|${DB_HOST}|" \
	"$DEST/wp-tests-config.php"
rm -f "$DEST/wp-tests-config.php.bak"

echo "WordPress test suite ready in $DEST."
