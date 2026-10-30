FROM php:8.3-apache

# 1. Instalar herramientas del sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalar extensiones de PHP (imap y pdo_mysql)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions imap pdo_mysql

# 3. Copiar ejecutable de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4. Configurar Apache
RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/app-directory.conf

WORKDIR /var/www/html

# 5. Copiar los archivos de Composer e instalar dependencias durante el BUILD
COPY php/composer.json php/composer.lock* ./
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

# 6. Copiar el resto del código del proyecto
COPY php/ ./
COPY public/ /opt/whatsapp-tareas/public/

EXPOSE 80