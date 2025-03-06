# Dockerfile

FROM php:8.1-apache

# Instalar las extensiones y dependencias necesarias
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    wget && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install intl pdo pdo_mysql gd zip && \
    a2enmod rewrite

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copiar archivo symfony.conf al directorio de sitios disponibles
COPY symfony.conf /etc/apache2/sites-available/symfony.conf

# Habilitar el sitio symfony
RUN a2ensite symfony.conf

# Quitar el sitio por defecto
RUN a2dissite 000-default.conf

# Copiar todo el proyecto al directorio de trabajo
COPY . /var/www/html

# Definir el directorio de trabajo
WORKDIR /var/www/html

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Ejecutar composer install
RUN composer install

# Exponer el puerto 80
EXPOSE 80

# Iniciar Apache
CMD ["apache2-foreground"]