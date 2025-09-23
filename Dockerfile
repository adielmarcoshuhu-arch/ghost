FROM php:8.1-apache

# Copia os arquivos do backend para o diretório do Apache
COPY backend/ /var/www/html/

# Habilita o módulo rewrite (opcional, se precisar de URLs amigáveis)
RUN a2enmod rewrite

# Expõe a porta 80
EXPOSE 80