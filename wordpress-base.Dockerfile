ARG WORDPRESS_CONTAINER_VERSION=latest
ARG WORDPRESS_CORE_VERSION=latest
FROM wordpress:${WORDPRESS_CONTAINER_VERSION} AS wordpress-base


#
# Configure user settings for www-data console operation
#
RUN usermod --shell /bin/bash www-data && \
    cp -a /etc/skel/. /var/www/ && \
    install --mode=700 --owner=www-data --group=www-data --directory ~/.ssh && \
    sed -i -e 's/#force_color_prompt=yes/force_color_prompt=yes/g' /var/www/.bashrc && \
    apt-get update && \
    apt-get upgrade -yq && \
    apt-get install -yq sudo bash-completion zip unzip && \
    mkdir -p /etc/bash_completion.d && \
    echo 'www-data ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers


#
# Configure Supervisor as entrypoint
#
# https://docs.docker.com/engine/containers/multi-service_container/#use-a-process-manager
# https://supervisord.org/
RUN set -eux; \
    apt-get install -yq supervisor && \
    mkdir -p /etc/supervisor/conf.d/ && \
    { \
        echo "[supervisord]"; \
        echo "nodaemon=true"; \
        echo "logfile=/dev/null"; \
        echo "logfile_maxbytes=0"; \
        echo "pidfile=/var/www/supervisord.pid"; \
        echo ""; \
        echo "[rpcinterface:supervisor]"; \
        echo "supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface"; \
        echo ""; \
        echo "[unix_http_server]"; \
        echo "file=/var/www/supervisor.sock"; \
        echo ""; \
        echo "[supervisorctl]"; \
        echo "serverurl=unix:///var/www/supervisor.sock"; \
        echo ""; \
        echo "[include]"; \
        echo "files = /etc/supervisor/conf.d/*.conf"; \
    } | tee /etc/supervisor/supervisord.conf && \
    { \
        echo "[program:apache2]"; \
        echo "command=apache2-foreground"; \
        echo "stdout_logfile=/dev/fd/1"; \
        echo "stdout_logfile_maxbytes=0"; \
        echo "redirect_stderr=true"; \
        echo "startsecs=0"; \
        echo "autorestart=false"; \
        echo "startretries=1"; \
    } | tee /etc/supervisor/conf.d/apache2.conf && \
    { \
        echo "[program:installer]"; \
        echo "command=/usr/bin/bash /var/www/html/.devcontainer/install.sh"; \
        echo "stdout_logfile=/dev/fd/1"; \
        echo "stdout_logfile_maxbytes=0"; \
        echo "redirect_stderr=true"; \
        echo "startsecs=0"; \
        echo "autorestart=false"; \
        echo "startretries=0"; \
    } | tee /etc/supervisor/conf.d/installer.conf
# https://github.com/docker-library/wordpress/blob/c82afd7240879748c5e4a64e5fb04e2d34172686/latest/php8.3/apache/Dockerfile
# NOTE: Default entrypoint skips all steps when unknown arguments given, see https://github.com/docker-library/wordpress/blob/c82afd7240879748c5e4a64e5fb04e2d34172686/latest/php8.3/apache/docker-entrypoint.sh
ENTRYPOINT ["/usr/bin/supervisord"]
CMD ["-c", "/etc/supervisor/supervisord.conf"]


#
# Configure HTTPS
#
RUN openssl genrsa -out /var/www/ssl-cert-snakeoil.key 2048 && \
    openssl req -new -subj "/C=/CN=localhost" -key /var/www/ssl-cert-snakeoil.key -out /var/www/ssl-cert-snakeoil.csr && \
    echo "subjectAltName = DNS:localhost" > /var/www/san.txt && \
    openssl x509 -req -days 730 -signkey /var/www/ssl-cert-snakeoil.key -in /var/www/ssl-cert-snakeoil.csr -extfile /var/www/san.txt -out /var/www/ssl-cert-snakeoil.pem && \
    rm /var/www/ssl-cert-snakeoil.csr /var/www/san.txt && \
    sed -i 's/SSLCertificateFile.*snakeoil\.pem/SSLCertificateFile \/var\/www\/ssl-cert-snakeoil.pem/g' $APACHE_CONFDIR/sites-available/default-ssl.conf && \
    sed -i 's/SSLCertificateKeyFile.*snakeoil\.key/SSLCertificateKeyFile \/var\/www\/ssl-cert-snakeoil.key/g' $APACHE_CONFDIR/sites-available/default-ssl.conf && \
    a2enmod ssl && \
    a2enmod socache_shmcb && \
    a2ensite default-ssl && \
    printf "<Directory /var/www/>\n    #Options Indexes FollowSymLinks\n    AllowOverride All\n    #Require all granted\n</Directory>" > $APACHE_CONFDIR/conf-enabled/wordpress.conf


#
# Configure php.ini
#
# @link https://www.php.net/manual/en/ini.core.php#ini.sect.file-uploads
# @link https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time
#
# NOTE: PHP_INI_DIR=/usr/local/etc/php
RUN printf "upload_max_filesize=0\npost_max_size=0" >> $PHP_INI_DIR/conf.d/wordpress-file-uploads.ini && \
    printf "max_execution_time=0" >> $PHP_INI_DIR/conf.d/wordpress-runtime.ini && \
    [ -f $PHP_INI_DIR/conf.d/opcache-recommended.ini ] && sed -i 's/opcache\.max_accelerated_files.*/opcache.max_accelerated_files=65535/g' $PHP_INI_DIR/conf.d/opcache-recommended.ini || true


#
# Install and configure Xdebug
#
# https://xdebug.org/docs/install#source
# https://pecl.php.net/package/xdebug
# https://github.com/xdebug/xdebug/blob/xdebug_3_3/src/debugger/com.c#L614
# https://github.com/xdebug/xdebug/blob/xdebug_3_5/src/debugger/com.c#L642
RUN cd /usr/local/lib/php/extensions/ && \
    curl https://pecl.php.net/get/xdebug-3.5.1.tgz --location --output - | tar xz && mv xdebug-* xdebug && \
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
RUN { \
        echo 'xdebug.idekey=VSCODE'; \
        echo 'xdebug.mode=develop,debug'; \
        #echo 'xdebug.start_with_request=trigger'; \
        #echo 'xdebug.log=/tmp/xdebug.log'; \
        echo xdebug.client_host=localhost; \
        #echo 'xdebug.client_port=9003'; \
    } | sudo tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug-config.ini


#
# Install composer
#
# @link https://getcomposer.org/download/#manual-download
# @link https://getcomposer.org/doc/articles/troubleshooting.md#operation-timed-out-ipv6-issues-
# @link https://github.com/composer/composer/issues/9358
#
# ENV COMPOSER_IPRESOLVE=4
# todo https://github.com/composer/composer/releases/latest/download/composer.phar
# ALTERNATIVE: COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
RUN curl --location --ipv4 --output /usr/local/bin/composer https://getcomposer.org/download/latest-stable/composer.phar && \
    chmod +x /usr/local/bin/composer && \
    mkdir -p /var/www/.composer && \
    chown www-data:www-data /var/www/.composer && \
    curl --location "https://github.com/bramus/composer-autocomplete/raw/master/composer-autocomplete" --output /etc/bash_completion.d/composer-autocomplete
ENV PATH="/var/www/.composer/vendor/bin:${PATH}"


#
# Install WP-CLI
#
# @link https://make.wordpress.org/cli/handbook/guides/installing/
#
RUN curl --location https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar --output wp && \
    chmod +x wp && \
    mv wp /usr/local/bin/ && \
    mkdir -p /var/www/.wp-cli && \
    # echo "path: /var/www/html" >> /var/www/.wp-cli/config.yml && \
    chown -R www-data:www-data /var/www/.wp-cli && \
    curl --location "https://github.com/wp-cli/wp-cli/raw/main/utils/wp-completion.bash" --output /etc/bash_completion.d/wp-completion.bash
# Suppress warnings about running wp-cli as root.
ENV WP_CLI_ALLOW_ROOT="true"
# https://developer.wordpress.org/cli/commands/cli/update/
# NOTE: Only nightly build are compatible with PHP 8.5 😱
RUN wp cli update --nightly --yes


#
# Install WordPress
#
# @link https://developer.wordpress.org/cli/commands/core/download/
#
RUN wp core download --version=${WORDPRESS_CORE_VERSION:-latest} --path=/var/www/html/ --skip-content --no-color --quiet && \
    mkdir -p /var/www/html/wp-content/plugins && \
    mkdir -p /var/www/html/wp-content/themes && \
    chown -R www-data:www-data /var/www


#
# HEALTHCHECK
#
HEALTHCHECK --interval=5s --timeout=5s --retries=55 --start-period=30s \
    CMD wp core is-installed --path=/var/www/html/ || exit 1


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
    sudo apt-get install -yq mariadb-client && \
    { \
        echo "[client]"; \
        echo "ssl-verify-server-cert=FALSE"; \
    } | sudo tee /etc/mysql/conf.d/ignore-ssl.cnf && \
    sudo apt-get install -yq git && \
    git config --global init.defaultBranch main && \
    sudo apt-get install -yq subversion ssh-client iproute2 inetutils-ping gettext


#
# PHP Composer Packages
#
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true && \
    composer global require --dev wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp


#
# NodeJS, NVM, and Corepack
#
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
    nvm install --lts && \
    npm set --global save-exact true && \
    { \
        echo 'alias yarn="corepack yarn"'; \
        echo 'alias yarnpkg="corepack yarnpkg"'; \
        echo 'alias pnpm="corepack pnpm"'; \
        echo 'alias pnpx="corepack pnpx"'; \
        echo 'alias npm="corepack npm"'; \
        echo 'alias npx="corepack npx"'; \
    } | tee --append $HOME/.bashrc && \
    corepack enable


#
# PHP Info Page
#
RUN echo "<?php phpinfo();" > /var/www/html/phpinfo.php


#
# Install Adminer
#
# NOTE: SSL status at www.adminer.org is seems unstable. So we use github url instead of "https://www.adminer.org/latest-mysql-en.php".
RUN curl https://github.com/vrana/adminer/releases/download/v6.1.1/adminer-6.1.1-mysql.php --location --output /var/www/html/adminer-mysql.php
COPY adminer.php /var/www/html/


#
# Deny access to hidden files
#
RUN echo "RewriteRule ^(\..*)$ - [F,L]" | tee --append /var/www/html/.htaccess


# Cleanup apt cache
RUN sudo rm -rf /var/lib/apt/lists/*
