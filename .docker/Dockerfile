FROM dunglas/frankenphp:1-php8.3-alpine

# Install system dependencies and tools
RUN apk add --no-cache \
    git \
    unzip \
    linux-headers \
    sqlite-dev \
    $PHPIZE_DEPS

# Install additional extensions needed (pcntl, posix, pdo_sqlite)
RUN install-php-extensions \
    pcntl \
    posix \
    pdo_sqlite

# Configure PHP memory limit for CLI and static analysis
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/docker-php-memlimit.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-a"]
