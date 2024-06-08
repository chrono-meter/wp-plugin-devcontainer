ARG TAG=latest
FROM wordpress:${TAG} AS wordpress-base


#
# Configure user settings for www-data console operation
#
RUN usermod --shell /bin/bash www-data; \
    cp -a /etc/skel/. /var/www/; \
    install --mode=700 --owner=www-data --group=www-data --directory ~/.ssh; \
    sed -i -e 's/#force_color_prompt=yes/force_color_prompt=yes/g' /var/www/.bashrc; \
    apt-get update; \
    apt-get install -yq sudo bash-completion unzip mariadb-client inetutils-ping; \
    rm -rf /var/lib/apt/lists/*; \
    mkdir -p /etc/bash_completion.d; \
    echo 'www-data ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers


#
# Configure HTTPS
#
RUN openssl genrsa -out /var/www/ssl-cert-snakeoil.key 2048 && \
    openssl req -new -subj "/C=/CN=localhost" -key /var/www/ssl-cert-snakeoil.key -out /var/www/ssl-cert-snakeoil.csr && \
    echo "subjectAltName = DNS:localhost" > /var/www/san.txt && \
    openssl x509 -req -days 365 -signkey /var/www/ssl-cert-snakeoil.key -in /var/www/ssl-cert-snakeoil.csr -extfile /var/www/san.txt -out /var/www/ssl-cert-snakeoil.pem && \
    rm /var/www/ssl-cert-snakeoil.csr /var/www/san.txt && \
    sed -i 's/SSLCertificateFile.*snakeoil\.pem/SSLCertificateFile \/var\/www\/ssl-cert-snakeoil.pem/g' /etc/apache2/sites-available/default-ssl.conf && \
    sed -i 's/SSLCertificateKeyFile.*snakeoil\.key/SSLCertificateKeyFile \/var\/www\/ssl-cert-snakeoil.key/g' /etc/apache2/sites-available/default-ssl.conf && \
    a2enmod ssl && \
    a2enmod socache_shmcb && \
    a2ensite default-ssl


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
# @link https://getcomposer.org/download/
#
RUN curl -sL https://getcomposer.org/installer | php; \
    mv composer.phar /usr/local/bin/composer; \
    mkdir /var/www/.composer; \
    chown www-data:www-data /var/www/.composer; \
    curl -sL "https://github.com/bramus/composer-autocomplete/raw/master/composer-autocomplete" -o /etc/bash_completion.d/composer-autocomplete
ENV PATH="/var/www/.composer/vendor/bin:${PATH}"


#
# Install WP-CLI
#
# @link https://wp-cli.org/#installing
#
RUN curl -sL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o wp; \
    chmod +x wp; \
    mv wp /usr/local/bin/; \
    mkdir /var/www/.wp-cli; \
    chown www-data:www-data /var/www/.wp-cli; \
    curl -sL "https://raw.githubusercontent.com/wp-cli/wp-cli/v2.9.0/utils/wp-completion.bash" -o /etc/bash_completion.d/wp-completion.bash


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
RUN sudo apt-get update; \
    sudo apt-get install -yq ssh-client zip python3
RUN wp package install wp-cli/dist-archive-command:@stable
RUN sudo pecl install xdebug; \
    { \
        echo 'xdebug.idekey=VSCODE'; \
        echo 'xdebug.mode=develop,debug'; \
        echo 'xdebug.start_with_request=trigger'; \
        #echo '#xdebug.log=/tmp/xdebug.log'; \
        #echo '#xdebug.client_host=host.docker.internal'; \
        #echo '#xdebug.client_port=9003'; \
    } | sudo tee $PHP_INI_DIR/conf.d/docker-php-ext-xdebug-config.ini; \
    sudo chown -R www-data:www-data /usr/local/etc/php; \
    docker-php-ext-enable xdebug
RUN composer global config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true; \
    composer global require --dev wp-coding-standards/wpcs
RUN echo "<?php phpinfo();" > /var/www/html/phpinfo.php
RUN curl https://www.adminer.org/latest-mysql-en.php --silent --location > /var/www/html/adminer-mysql-en.php; \
    { \
        echo "<?php if ( ! count( \$_GET ) ) { \$_POST['auth'] = array('driver' => 'server', 'server' => \$_ENV['WORDPRESS_DB_HOST'], 'username' => \$_ENV['WORDPRESS_DB_USER'], 'password' => \$_ENV['WORDPRESS_DB_PASSWORD'], 'db' => \$_ENV['WORDPRESS_DB_NAME']); } require_once __DIR__ . '/adminer-mysql-en.php';"; \
    } > /var/www/html/adminer.php
