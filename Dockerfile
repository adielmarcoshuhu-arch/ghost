FROM php:8.1-apache

# Copia o api_ads.php diretamente para o diretório do Apache
COPY api_ads.php /var/www/html/

# Habilita o módulo rewrite (opcional, se precisar de URLs amigáveis)
RUN a2enmod rewrite

# Expõe a porta 80
EXPOSE 80