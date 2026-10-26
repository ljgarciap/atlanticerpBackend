FROM php:8.3.36-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libsodium-dev \
        unzip \
        git \
        curl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# mlocati/docker-php-extension-installer para las extensiones que NO dependen de pecl.php.net —
# resuelve los paquetes de sistema de cada una automáticamente (ej. libpng/libjpeg/libfreetype
# para gd), sin necesitar docker-php-ext-configure a mano.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pcntl zip sodium gd

# redis compilado desde el tarball de GitHub, NO vía pecl.php.net ni el instalador de arriba (que
# también usa pecl.php.net para redis específicamente) — pecl.php.net tuvo una caída sostenida
# (504 Gateway Timeout, 3 intentos fallidos en ~20 minutos) el 2026-07-20 que rompió 3 veces
# seguidas el deploy a dev.atlanticerp.ai. $PHPIZE_DEPS ya viene definido por la imagen base php:8.3-fpm.
RUN apt-get update && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && curl -fsSL https://github.com/phpredis/phpredis/archive/refs/tags/6.3.0.tar.gz -o /tmp/phpredis.tar.gz \
    && mkdir -p /tmp/phpredis && tar -xzf /tmp/phpredis.tar.gz -C /tmp/phpredis --strip-components=1 \
    && cd /tmp/phpredis && phpize && ./configure && make -j"$(nproc)" && make install \
    && docker-php-ext-enable redis \
    && cd / && rm -rf /tmp/phpredis /tmp/phpredis.tar.gz \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY memory.ini /usr/local/etc/php/conf.d/memory.ini
# CAP-02: configuración explícita de php-fpm (se carga después de zz-docker.conf, por orden alfabético)
COPY zz-fpm.conf /usr/local/etc/php-fpm.d/zz-fpm.conf

WORKDIR /var/www/html
