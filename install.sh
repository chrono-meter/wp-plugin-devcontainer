#!/bin/bash
set -euo pipefail

# Enable user-specified Apache modules.
if [ -n "$APACHE_EXTRA_MODULES" ]; then
    for mod in $APACHE_EXTRA_MODULES; do
        sudo a2enmod "$mod";
    done;
fi
/etc/init.d/apache2 reload

cd /var/www/html/

# Check if WordPress is already installed. If it is, exit the script.
# https://developer.wordpress.org/cli/commands/core/is-installed/
wp core is-installed 2>/dev/null && exit 0 || true

# https://developer.wordpress.org/cli/commands/config/create/
echo "require_once __DIR__ . '/.devcontainer/wp-config-extra.php';" | wp config create \
    --dbhost=${WORDPRESS_DB_HOST:-db} \
    --dbname=${WORDPRESS_DB_NAME:-wordpress} \
    --dbuser=${WORDPRESS_DB_USER:-wordpress} \
    --dbpass=${WORDPRESS_DB_PASSWORD:-wordpress} \
    --dbprefix=${WORDPRESS_DB_PREFIX:-wp_} \
    --dbcharset=${WORDPRESS_DB_CHARSET:-utf8} \
    --dbcollate=${WORDPRESS_DB_COLLATE:-} \
    --locale=${WORDPRESS_LOCALE:-en_US} \
    --extra-php

# Check again. If WordPress is installed, `wp rewrite flush` and exit.
if wp core is-installed 2>/dev/null; then
    # wp core update-db

    wp cache flush

    wp transient delete --all

    # Requires "wp-cli.yml". See https://github.com/wp-cli/rewrite-command/tree/main#wp-rewrite-flush
    # If your site's URL structre has still broken, open http://localhost/wp-admin/options-permalink.php
    wp rewrite flush --hard

    exit 0
fi

# https://developer.wordpress.org/cli/commands/core/install/
wp core install \
    --url=${WORDPRESS_URL:-http://localhost} \
    --title=${WORDPRESS_TITLE:-"WordPress in dev container"} \
    --admin_user=${WORDPRESS_ADMIN_USER:-admin} \
    --admin_password=${WORDPRESS_ADMIN_PASSWORD:-password} \
    --admin_email=${WORDPRESS_ADMIN_EMAIL:-admin@wordpress.local} \
    --locale=${WORDPRESS_LOCALE:-en_US} \
    --skip-email

# Avoid dependency on default themes that are added every year.
# https://wordpress.org/themes/classic/
wp theme install classic
if ! wp theme list --status=active --quiet; then
    wp theme activate classic
fi

wp plugin install \
    query-monitor \
    wordpress-beta-tester \
    user-switching

# https://github.com/WordPress/mcp-adapter/blob/trunk/docs/getting-started/README.md
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate

wp eval 'wp_mail("you@example.com", "Installation Complete", "WordPress has been successfully installed.");'

exit 0
