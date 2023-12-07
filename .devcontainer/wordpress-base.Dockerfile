ARG TAG=latest
FROM wordpress:${TAG}


#
# Configure user settings for www-data console operation
#
RUN usermod --shell /bin/bash www-data; \
    cp -a /etc/skel/. /var/www/; \
    sed -i -e 's/#force_color_prompt=yes/force_color_prompt=yes/g' /var/www/.bashrc; \
    apt-get update; \
    apt-get install -yq sudo bash-completion; \
    rm -rf /var/lib/apt/lists/*; \
    echo 'www-data ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers


#
# Configure php.ini
#
# @link https://www.php.net/manual/en/ini.core.php#ini.sect.file-uploads
# @link https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time
#
RUN printf "upload_max_filesize=0\npost_max_size=1024M" >> $PHP_INI_DIR/conf.d/wordpress-file-uploads.ini; \
    printf "max_execution_time=0" >> $PHP_INI_DIR/conf.d/wordpress-runtime.ini


#
# Install composer
#
# @link https://getcomposer.org/download/
#
RUN curl -sL https://getcomposer.org/installer | php; \
    mv composer.phar /usr/local/bin/composer; \
    mkdir /var/www/.composer; \
    chown www-data:www-data /var/www/.composer
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
    chown www-data:www-data /var/www/.wp-cli


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
# Set effective user
#
USER www-data
WORKDIR /var/www/html
