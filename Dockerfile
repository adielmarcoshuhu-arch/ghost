FROM php:8.1-apache

# Copia os arquivos para o diretório do Apache
COPY api_ads.php /var/www/html/
COPY index.php /var/www/html/

# Habilita o módulo rewrite
RUN a2enmod rewrite

# Expõe a porta 80
EXPOSE 80