# Gunakan image PHP resmi dengan Apache
FROM php:8.1-apache

# Install dependensi sistem dan driver PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Aktifkan mod_rewrite Apache (berguna jika ada .htaccess nantinya)
RUN a2enmod rewrite

# Copy seluruh file projek ke direktori web Apache
COPY . /var/www/html/

# Set permission agar Apache bisa membaca file
RUN chown -R www-data:www-data /var/www/html/

# Expose port 80
EXPOSE 80

# Jalankan Apache di foreground
CMD ["apache2-foreground"]
