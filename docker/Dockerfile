# =============================================================================
#  Immagine di sviluppo: Apache + PHP, come su Aruba Linux Easy
# =============================================================================
#  Serve solo in locale. La produzione e un hosting condiviso: nessun container,
#  nessun demone aggiuntivo. Qui riproduciamo lo stesso stack per accorgerci in
#  sviluppo di eventuali differenze di comportamento.
# =============================================================================

FROM php:8.3-apache

# --- Estensioni PHP ----------------------------------------------------------
# Le stesse disponibili su Aruba. Imagick e preferito a GD per la resa della
# filigrana, ma il codice funziona con entrambi.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        libmagickwand-dev \
        libsodium-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo_mysql \
        mysqli \
        intl \
        zip \
        exif \
        opcache \
        sodium \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    # Pulizia nello stesso layer: altrimenti i pacchetti di build resterebbero
    # nell'immagine finale.
    && apt-get purge -y --auto-remove libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

# --- Apache ------------------------------------------------------------------
RUN a2enmod rewrite headers deflate expires

# Il document root e public/: tutto il resto resta fuori dalla portata del web.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# AllowOverride All: senza, il file public/.htaccess verrebbe ignorato e gli
# indirizzi puliti non funzionerebbero.
RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/baraonda.conf \
    && a2enconf baraonda

# --- Configurazione PHP di sviluppo ------------------------------------------
RUN { \
        echo 'display_errors = On'; \
        echo 'error_reporting = E_ALL'; \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 128M'; \
        echo 'max_execution_time = 300'; \
        echo 'max_file_uploads = 60'; \
        echo 'date.timezone = Europe/Rome'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 1'; \
    } > /usr/local/etc/php/conf.d/baraonda.ini

# --- Composer ----------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Le cartelle scrivibili devono appartenere all'utente di Apache.
RUN mkdir -p storage/originals storage/processed storage/products \
             storage/logs storage/cache storage/temp storage/sessions \
             public/uploads \
    && chown -R www-data:www-data storage public/uploads

EXPOSE 80
