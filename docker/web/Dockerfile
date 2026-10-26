# Multi-stage build pulling pinned official Composer binary
FROM composer:2.8.12 AS composer_stage

# Base runtime: Apache 2.4.69 + PHP 8.5.12 on Debian 13 (Trixie) minimal
FROM php:8.5.12-apache-trixie

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PORT=8080 \
    SSL_PORT=8443

# Install required PDO MySQL extension
RUN docker-php-ext-install -j$(nproc) pdo_mysql

# Copy Composer binary from pinned official image
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# Enable required Apache modules and disable unused/dangerous modules
# -f flag prevents interactive prompts during module deactivation
RUN a2enmod rewrite headers ssl socache_shmcb \
    && a2dismod -f autoindex status

# Reconfigure Apache listening ports for non-root execution (must be >= 1024)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/Listen 443/Listen 8443/' /etc/apache2/ports.conf

# Generate self-signed TLS certificate for local HTTPS on port 8443
# Private key permissions restricted to root:www-data with mode 640
RUN mkdir -p /etc/ssl/certs /etc/ssl/private \
    && openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
       -keyout /etc/ssl/private/apache-selfsigned.key \
       -out /etc/ssl/certs/apache-selfsigned.crt \
       -subj "/C=ID/ST=Jakarta/L=Jakarta/O=SecureLMS/OU=IT/CN=localhost" \
    && chown -R root:www-data /etc/ssl/private /etc/ssl/certs \
    && chmod 750 /etc/ssl/private \
    && chmod 640 /etc/ssl/private/apache-selfsigned.key \
    && chmod 644 /etc/ssl/certs/apache-selfsigned.crt

# Deploy hardened Apache & PHP configurations
COPY docker/web/security.conf /etc/apache2/conf-available/security.conf
COPY docker/web/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY docker/web/php.ini /usr/local/etc/php/conf.d/99-hardening.ini

# Activate custom configuration, disable default HTTP site, enable SSL site
RUN a2enconf security \
    && a2dissite 000-default \
    && a2ensite default-ssl

# Set working directory and copy application source with unprivileged ownership
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

# Establish writable storage directories and Apache runtime directories
RUN mkdir -p /var/www/html/storage/uploads \
             /var/www/html/storage/logs \
             /var/run/apache2 \
             /var/lock/apache2 \
             /var/log/apache2 \
    && chown -R www-data:www-data /var/www/html/storage /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chmod -R 0770 /var/www/html/storage

# Deploy entrypoint script
COPY docker/web/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Generate Composer autoloader
RUN composer dump-autoload --optimize --no-interaction

# Enforce unprivileged user execution
USER www-data

EXPOSE 8080 8443

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
