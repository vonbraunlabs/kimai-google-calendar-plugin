# PHP toolchain for the unit tests (CLAUDE.md §4), same PHP version and extensions as the CI job.
# The source is mounted, not copied: see the gcal-test service in docker-compose.yml.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libxslt1-dev libpng-dev \
    && docker-php-ext-install intl zip xsl gd pdo_mysql \
    && pecl install pcov && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
