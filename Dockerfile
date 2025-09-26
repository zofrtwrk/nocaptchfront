 # Use lightweight PHP + Apache image (serves HTML & PHP)
FROM php:8.2-apache

# Copy site files into Apache web root
COPY . /var/www/html

# (Optional) enable .htaccess + rewrites if you need pretty URLs
RUN a2enmod rewrite

# (Optional) tighten permissions
RUN chown -R www-data:www-data /var/www/html

# Expose HTTP
EXPOSE 80

# Start Apache in the foreground
CMD ["apache2-foreground"]
