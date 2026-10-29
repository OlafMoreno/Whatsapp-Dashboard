#!/bin/sh
set -eu

case "$MYSQLADMIN_USER" in
    *[!A-Za-z0-9_]*|'')
        echo "MYSQLADMIN_USER solo puede contener letras, números y guiones bajos." >&2
        exit 1
        ;;
esac

case "$MYSQLADMIN_PASSWORD" in
    *[!A-Za-z0-9_-]*|'')
        echo "MYSQLADMIN_PASSWORD solo puede contener letras, números, guiones y guiones bajos." >&2
        exit 1
        ;;
esac

case "$MARIADB_DATABASE" in
    *[!A-Za-z0-9_]*|'')
        echo "MARIADB_DATABASE solo puede contener letras, números y guiones bajos." >&2
        exit 1
        ;;
esac

mariadb --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" --execute="
CREATE USER IF NOT EXISTS '$MYSQLADMIN_USER'@'%' IDENTIFIED BY '$MYSQLADMIN_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MARIADB_DATABASE\`.* TO '$MYSQLADMIN_USER'@'%';
"

echo "Usuario administrador de la base de tareas para phpMyAdmin configurado en MariaDB."
