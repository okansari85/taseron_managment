FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm

USER root

RUN rm -f /etc/apt/sources.list.d/yarn.list \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server \
    && docker-php-ext-install pdo_mysql mysqli \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

USER vscode
