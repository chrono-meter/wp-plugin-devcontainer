ARG TAG=latest
FROM wordpress:${TAG} AS wordpress-base


#
# Configure user settings for www-data console operation
#
RUN usermod --shell /bin/bash www-data && \
    cp -a /etc/skel/. /var/www/ && \
    install --mode=700 --owner=www-data --group=www-data --directory ~/.ssh && \
    sed -i -e 's/#force_color_prompt=yes/force_color_prompt=yes/g' /var/www/.bashrc && \
    apt-get update && \
    apt-get install -yq sudo bash-completion unzip mariadb-client iproute2 inetutils-ping && \
    rm -rf /var/lib/apt/lists/* && \
    mkdir -p /etc/bash_completion.d && \
    echo 'www-data ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers


#
# Configure HTTPS
#
RUN openssl genrsa -out /var/www/ssl-cert-snakeoil.key 2048 && \
    openssl req -new -subj "/C=/CN=localhost" -key /var/www/ssl-cert-snakeoil.key -out /var/www/ssl-cert-snakeoil.csr && \
    echo "subjectAltName = DNS:localhost" > /var/www/san.txt && \
    openssl x509 -req -days 365 -signkey /var/www/ssl-cert-snakeoil.key -in /var/www/ssl-cert-snakeoil.csr -extfile /var/www/san.txt -out /var/www/ssl-cert-snakeoil.pem && \
    rm /var/www/ssl-cert-snakeoil.csr /var/www/san.txt && \
    sed -i 's/SSLCertificateFile.*snakeoil\.pem/SSLCertificateFile \/var\/www\/ssl-cert-snakeoil.pem/g' $APACHE_CONFDIR/sites-available/default-ssl.conf && \
    sed -i 's/SSLCertificateKeyFile.*snakeoil\.key/SSLCertificateKeyFile \/var\/www\/ssl-cert-snakeoil.key/g' $APACHE_CONFDIR/sites-available/default-ssl.conf && \
    a2enmod ssl && \
    a2enmod socache_shmcb && \
    a2ensite default-ssl && \
    printf "<Directory /var/www/>\n    #Options Indexes FollowSymLinks\n    AllowOverride All\n    #Require all granted\n</Directory>" > $APACHE_CONFDIR/conf-enabled/wordpress.conf


#
# Setup testing environment.
#
ENV TEST_ENV_HTTP_PORT=81
ENV TEST_ENV_HTTPS_PORT=444
ENV TEST_ENV_TABLE_PREFIX=test_
ENV TEST_ENV_PATH=/var/www/html/.test
ENV TEST_E2E_PATH=/var/www/html/.workspace/src/e2e
# NOTE: APACHE_CONFDIR=/etc/apache2
RUN mkdir -p ${TEST_ENV_PATH} && \
    { \
        echo "Listen ${TEST_ENV_HTTP_PORT}"; \
        echo '<IfModule ssl_module>'; \
        echo "    Listen ${TEST_ENV_HTTPS_PORT}"; \
        echo '</IfModule>'; \
        cat $APACHE_CONFDIR/sites-available/000-default.conf; \
        cat $APACHE_CONFDIR/sites-available/default-ssl.conf; \
    } | tee $APACHE_CONFDIR/sites-available/testing.conf && \
    sed -i "s/80/${TEST_ENV_HTTP_PORT}/" $APACHE_CONFDIR/sites-available/testing.conf && \
    sed -i "s/443/${TEST_ENV_HTTPS_PORT}/" $APACHE_CONFDIR/sites-available/testing.conf && \
    sed -i "s|/var/www/html|${TEST_ENV_PATH}|" $APACHE_CONFDIR/sites-available/testing.conf && \
    ln -s $APACHE_CONFDIR/sites-available/testing.conf $APACHE_CONFDIR/sites-enabled/testing.conf


#
# Configure php.ini
#
# @link https://www.php.net/manual/en/ini.core.php#ini.sect.file-uploads
# @link https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time
#
RUN printf "upload_max_filesize=0\npost_max_size=1024M" >> $PHP_INI_DIR/conf.d/wordpress-file-uploads.ini; \
    printf "max_execution_time=0" >> $PHP_INI_DIR/conf.d/wordpress-runtime.ini; \
    sed -i 's/opcache\.max_accelerated_files.*/opcache.max_accelerated_files=65535/g' $PHP_INI_DIR/conf.d/opcache-recommended.ini


#
# Install composer
#
# @link https://getcomposer.org/download/#manual-download
# @link https://getcomposer.org/doc/articles/troubleshooting.md#operation-timed-out-ipv6-issues-
# @link https://github.com/composer/composer/issues/9358
#
ENV COMPOSER_IPRESOLVE=4
RUN curl --location --ipv4 --output /usr/local/bin/composer https://getcomposer.org/download/latest-stable/composer.phar && \
    chmod +x /usr/local/bin/composer && \
    mkdir -p /var/www/.composer && \
    chown www-data:www-data /var/www/.composer && \
    curl --location "https://github.com/bramus/composer-autocomplete/raw/master/composer-autocomplete" --output /etc/bash_completion.d/composer-autocomplete
ENV PATH="/var/www/.composer/vendor/bin:${PATH}"


#
# Install WP-CLI
#
# @link https://wp-cli.org/#installing
#
RUN curl --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output wp && \
    chmod +x wp && \
    mv wp /usr/local/bin/ && \
    mkdir -p /var/www/.wp-cli && \
    # echo "path: /var/www/html" >> /var/www/.wp-cli/config.yml && \
    chown -R www-data:www-data /var/www/.wp-cli && \
    curl --location "https://raw.githubusercontent.com/wp-cli/wp-cli/v2.9.0/utils/wp-completion.bash" --output /etc/bash_completion.d/wp-completion.bash


#
# Install and configure Xdebug
#
# https://xdebug.org/docs/install#source
# https://pecl.php.net/package/xdebug
# https://github.com/xdebug/xdebug/blob/xdebug_3_3/src/debugger/com.c#L614
RUN cd /usr/local/lib/php/extensions/ && \
    curl https://pecl.php.net/get/xdebug-3.3.2.tgz --location --output - | tar xz && mv xdebug-* xdebug && \
    cd xdebug && \
    sed -i 's/XLOG_ERR, "NOCON"/XLOG_INFO, "NOCON"/g' src/debugger/com.c && \
    phpize && ./configure --enable-xdebug && make && \
    make install && \
    echo "zend_extension=xdebug" | tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini && \
    { \
        echo 'xdebug.idekey=VSCODE'; \
        echo 'xdebug.mode=develop,debug'; \
        #echo 'xdebug.start_with_request=trigger'; \
        #echo 'xdebug.log=/tmp/xdebug.log'; \
        echo xdebug.client_host=host.docker.internal; \
        #echo 'xdebug.client_port=9003'; \
    } | tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug-config.ini


#
# Install WordPress
#
RUN cp -a /usr/src/wordpress/. /var/www/html/; \
    mkdir -p /var/www/html/wp-content/plugins; \
    mkdir -p /var/www/html/wp-content/themes; \
    chown -R www-data:www-data /var/www


# 
# Inject wp-setup.php calling into docker-entrypoint.sh
#
# https://github.com/docker-library/wordpress/blob/master/latest/php8.1/apache/Dockerfile
# https://github.com/docker-library/wordpress/blob/master/docker-entrypoint.sh
#
RUN sed -i 's/exec "\$@"/php \/var\/www\/html\/.workspace\/.devcontainer\/wp-setup.php \&\nexec "\$@"/g' /usr/local/bin/docker-entrypoint.sh


#
# HEALTHCHECK
#
HEALTHCHECK --interval=5s --timeout=5s --retries=55 --start-period=30s \
    CMD bash -c "[ -f /var/www/wp-setup.complete ]"


#
# Set effective user
#
USER www-data
WORKDIR /var/www/html


#
# Configure Dev container
#
FROM wordpress-base AS devcontainer
RUN sudo apt-get update && \
    sudo apt-get install -yq ssh-client zip python3 git
# https://github.com/nvm-sh/nvm?tab=readme-ov-file#manual-install
ENV NVM_DIR="/var/www/.nvm"
RUN git clone https://github.com/nvm-sh/nvm.git "$NVM_DIR" && \
    cd "$NVM_DIR"  && \
    git checkout `git describe --abbrev=0 --tags --match "v[0-9]*" $(git rev-list --tags --max-count=1)` && \
    \. "$NVM_DIR/nvm.sh" && \
    { \
        echo '[ -s "$NVM_DIR/nvm.sh" ] && \\. "$NVM_DIR/nvm.sh" # This loads nvm'; \
        echo '[ -s "$NVM_DIR/bash_completion" ] && \\. "$NVM_DIR/bash_completion"  # This loads nvm bash_completion'; \
    } | tee --append $HOME/.bashrc && \
    command -v nvm && \
    nvm install --lts
# Install Playwright.
RUN \. "$NVM_DIR/nvm.sh" && \
    npm install -g playwright@latest && \
    npx playwright install --with-deps
#RUN wp package install wp-cli/dist-archive-command:@stable
RUN { \
        echo 'xdebug.idekey=VSCODE'; \
        echo 'xdebug.mode=develop,debug'; \
        #echo 'xdebug.start_with_request=trigger'; \
        #echo 'xdebug.log=/tmp/xdebug.log'; \
        echo xdebug.client_host=localhost; \
        #echo 'xdebug.client_port=9003'; \
    } | sudo tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug-config.ini
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
    composer global require --dev wp-coding-standards/wpcs
RUN echo "<?php phpinfo();" > /var/www/html/phpinfo.php
RUN curl https://www.adminer.org/latest-mysql-en.php --location --output /var/www/html/adminer-mysql-en.php && \
    { \
        echo "<?php if ( ! count( \$_GET ) ) { \$_POST['auth'] = array('driver' => 'server', 'server' => \$_ENV['WORDPRESS_DB_HOST'], 'username' => \$_ENV['WORDPRESS_DB_USER'], 'password' => \$_ENV['WORDPRESS_DB_PASSWORD'], 'db' => \$_ENV['WORDPRESS_DB_NAME']); } require_once __DIR__ . '/adminer-mysql-en.php';"; \
    } > /var/www/html/adminer.php
