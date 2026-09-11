#!/usr/bin/env bash
# Install WordPress test library
# Usage: bash bin/setup-wp-tests.sh wordpress_test root mypass localhost
DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
set -e
mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"
# Install WP core
if [ ! -d "$WP_CORE_DIR/src" ]; then
    curl -sL https://wordpress.org/latest.tar.gz | tar -xz -C /tmp
    mv /tmp/wordpress "$WP_CORE_DIR"
fi
# Install test suite
if [ ! -f "$WP_TESTS_DIR/includes/functions.php" ]; then
    svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes "$WP_TESTS_DIR/includes"
    svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data    "$WP_TESTS_DIR/data"
    cat > "$WP_TESTS_DIR/wp-tests-config.php" << CONFIG
<?php
define('ABSPATH',         '${WP_CORE_DIR}/src/');
define('WP_DEFAULT_THEME','default');
define('DB_HOST',         '${DB_HOST}');
define('DB_NAME',         '${DB_NAME}');
define('DB_USER',         '${DB_USER}');
define('DB_PASSWORD',     '${DB_PASS}');
define('DB_CHARSET',      'utf8');
define('DB_COLLATE',      '');
\$table_prefix = 'wptests_';
define('WP_DEBUG', true);
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL',  'admin@example.org');
define('WP_TESTS_TITLE',  'Test Blog');
CONFIG
fi
# Create DB
mysql -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -h"$DB_HOST" -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
echo "Done. Run: cd tests && ../vendor/bin/phpunit --testdox"
