#!/bin/sh
# Runs once, on the FIRST start of the MariaDB container, after the official
# entrypoint has created MARIADB_DATABASE and MARIADB_USER.
#
# Creates a parallel <DB>_test database and grants the app user full privileges
# on it so PHPUnit (CakePHP test datasource) can lock and rebuild it from
# fixtures.
set -e

TEST_DB="${MARIADB_DATABASE}_test"

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${TEST_DB}\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
