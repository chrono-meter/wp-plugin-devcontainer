#!/usr/bin/env bash
sudo pecl install xdebug; \
    { \
        echo 'xdebug.idekey=VSCODE'; \
        echo 'xdebug.mode=develop,debug'; \
        echo 'xdebug.start_with_request=trigger'; \
        #echo '#xdebug.log=/tmp/xdebug.log'; \
        #echo '#xdebug.client_host=host.docker.internal'; \
        #echo '#xdebug.client_port=9003'; \
    } | sudo tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug-config.ini; \
    sudo chown -R www-data:www-data /usr/local/etc/php; \
    docker-php-ext-enable xdebug; \
    /etc/init.d/apache2 reload

composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
    composer global require --dev wp-coding-standards/wpcs; \
    echo "<?php phpinfo();" > phpinfo.php; \
    curl https://www.adminer.org/latest-mysql-en.php --silent --location > adminer-mysql-en.php; \
    { \
        echo "<?php if ( ! count( \$_GET ) ) { \$_POST['auth'] = array('driver' => 'server', 'server' => \$_ENV['WORDPRESS_DB_HOST'], 'username' => \$_ENV['WORDPRESS_DB_USER'], 'password' => \$_ENV['WORDPRESS_DB_PASSWORD'], 'db' => \$_ENV['WORDPRESS_DB_NAME']); } require_once __DIR__ . '/adminer-mysql-en.php';"; \
    } > /var/www/html/adminer.php
