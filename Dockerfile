FROM php:8.2-apache
MAINTAINER leonardo.robol@unipi.it

RUN apt-get update \
    && apt-get install -y default-mysql-client libldap2-dev libicu-dev libsasl2-dev libldb-dev locales locales-all \
    && ln -s /usr/lib/x86_64-linux-gnu/libldap.so /usr/lib/libldap.so \
    && rm -r /var/lib/apt/lists/* \
    && docker-php-ext-install ldap pdo pdo_mysql iconv intl

COPY web/ /var/www/html/
COPY vendor/    /var/www/vendor/
ADD tables.my.sql /tables.my.sql
ADD entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
