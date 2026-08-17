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

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-a"]
