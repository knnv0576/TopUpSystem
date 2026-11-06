# PHP2Shell（CVE-2026-109618、CVE-2026-109619）在 8.5 分支的修補版本是 8.5.12。
# 升級時只能往上調整版本；低於下方門檻時建置會失敗。
# 映像都鎖定 digest，內容不會在不知情下改變；更新方式見 docs/iso27001-mapping.md。
FROM php:8.5.12-fpm-bookworm@sha256:75dc898a8861a777f14c57b2afbb811a848735701593cbc354e4194807a88f3e

RUN php -r 'exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);' \
    || (echo "PHP version is vulnerable to PHP2Shell" >&2; exit 1)

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git openssl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# 所有 PHP 程序（fpm、queue、scheduler、artisan、composer）都用同一個非 root 使用者，
# 避免檔案所有權衝突。專案目錄的所有權由 compose 的 init 服務設定。
ENV COMPOSER_HOME=/tmp/composer
USER www-data
