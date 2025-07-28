# Use PHP 8.2 with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install system dependencies
RUN apt-get update && apt-get install -y \
    unzip \
    libzip-dev \
    git \
    curl \
    && docker-php-ext-install zip

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Set folder permissions (including uploads)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

# Install PHP dependencies using Composer
RUN composer install --no-interaction --no-progress --optimize-autoloader

# Apache config for uploads
COPY apache-config.conf /etc/apache2/sites-enabled/000-default.conf

# Render exposes port 10000
EXPOSE 10000

CMD ["apache2-foreground"]
