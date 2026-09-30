#!/bin/bash
set -euo pipefail

# Check if WordPress is already installed. If it is, exit the script.
# https://developer.wordpress.org/cli/commands/core/is-installed/
wp core is-installed --path=/var/www/html/ && exit 0 || true

# https://developer.wordpress.org/cli/commands/config/create/
echo "require_once __DIR__ . '/.devcontainer/wp-config-extra.php';" | wp config create --path=/var/www/html/ \
    --dbhost=${WORDPRESS_DB_HOST:-db} \
    --dbname=${WORDPRESS_DB_NAME:-wordpress} \
    --dbuser=${WORDPRESS_DB_USER:-wordpress} \
    --dbpass=${WORDPRESS_DB_PASSWORD:-wordpress} \
    --dbprefix=${WORDPRESS_DB_PREFIX:-wp_} \
    --dbcharset=${WORDPRESS_DB_CHARSET:-utf8} \
    --dbcollate=${WORDPRESS_DB_COLLATE:-} \
    --locale=${WORDPRESS_LOCALE:-en_US} \
    --extra-php

# https://developer.wordpress.org/cli/commands/core/install/
wp core install --path=/var/www/html/ \
    --url=${WORDPRESS_URL:-http://localhost} \
    --title=${WORDPRESS_TITLE:-"WordPress in dev container"} \
    --admin_user=${WORDPRESS_ADMIN_USER:-admin} \
    --admin_password=${WORDPRESS_ADMIN_PASSWORD:-password} \
    --admin_email=${WORDPRESS_ADMIN_EMAIL:-admin@wordpress.local} \
    --locale=${WORDPRESS_LOCALE:-en_US} \
    --skip-email

# Avoid dependency on default themes that are added every year.
# https://wordpress.org/themes/classic/
wp theme install --path=/var/www/html/ \
    classic --activate

wp plugin install --path=/var/www/html/ \
    query-monitor \
    wordpress-beta-tester \
    user-switching

wp eval --path=/var/www/html/ 'wp_mail("you@example.com", "Installation Complete", "WordPress has been successfully installed.");'

exit 0
