# Use PHP 8.2 with Apache
FROM php:8.2-apache

# Enable mod_rewrite for SPA routing
RUN a2enmod rewrite

# Install dependencies
RUN apt-get update && apt-get install -y unzip libzip-dev git curl \
    && docker-php-ext-install zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Correct file permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

# Install PHP dependencies
RUN composer install --no-interaction --no-progress --optimize-autoloader

# Copy Apache site config
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf
RUN ln -sf /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-enabled/000-default.conf

# Expose port 80 for Render routing
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]
