# The plugin test image plus the MySQL/MariaDB and PostgreSQL PDO drivers, which the base image does not carry.
FROM asignua-plugin-php:8.4
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install -j$(nproc) pdo_mysql pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*
