FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/app-directory.conf \
    && a2enconf app-directory \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY php/ ./
COPY public/index.html /opt/whatsapp-tareas/dashboard.html
COPY .htaccess ./

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1/health') === false ? 1 : 0);"]
