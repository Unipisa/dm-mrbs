#!/bin/bash

# shell default value: https://unix.stackexchange.com/questions/122845/using-a-b-for-variable-assignment-in-scripts
if [ -z "$MRBS_DB_HOST" ]; then
  echo >&2 'error: missing required MRBS_DB_HOST env var'
  exit 1
fi

if [ -z "$MRBS_DB_USER" ]; then
  echo >&2 'error: missing required MRBS_DB_USER env var'
  exit 1
fi

if [ -z "$MRBS_DB_PASSWORD" ]; then
  echo >&2 'error: missing required MRBS_DB_PASSWORD env var'
  exit 1
fi

: "${MRBS_DB_SYS:=mysql}"
: "${MRBS_DB_NAME:=mrbs}"
: "${MRBS_ADMIN_NAMES:=\"[ 'administrator' ]\"}"
: "${MRBS_ADMIN_PASSWORD:=secret}"
: "${MRBS_TIMEZONE=${TIMEZONE:-"GMT"}}"
: "${MRBS_COMPANY:=Your Company}"
: "${MRBS_DEFAULT_VIEW:=day}"
: "${MRBS_ENABLE_REGISTRATION:=true}"
: "${MRBS_DENY_PUBLIC_ACCESS:=false}"
: "${MRBS_UNIPI_VALID_BASES:=[]}"
: "${MRBS_UNIPI_VALID_UIDS:=[]}"
: "${MRBS_UNIPI_VALID_DEPARTMENTS:=[]}"
: "${MRBS_READONLY_ROOMS:=[]}"
: "${MRBS_UNIPI_DM_VALID_ROLES:=[]}"
: "${MRBS_HIDDEN_DAYS:=[]}"

# injected parameters and update msbr config
cd /var/www/html

cp config.inc.php.sample config.inc.php

sed -i "s|@MRBS_TIMEZONE@|${MRBS_TIMEZONE}|" config.inc.php
sed -i "s|@MRBS_DB_SYS@|${MRBS_DB_SYS}|" config.inc.php
sed -i "s|@MRBS_DB_HOST@|${MRBS_DB_HOST}|" config.inc.php
sed -i "s|@MRBS_DB_NAME@|${MRBS_DB_NAME}|" config.inc.php
sed -i "s|@MRBS_DB_USER@|${MRBS_DB_USER}|" config.inc.php
sed -i "s|@MRBS_DB_PASSWORD@|${MRBS_DB_PASSWORD}|" config.inc.php
sed -i "s|@MRBS_ADMIN_NAMES@|${MRBS_ADMIN_NAMES}|" config.inc.php
sed -i "s|@MRBS_LDAP_HOST@|${MRBS_LDAP_HOST}|" config.inc.php
sed -i "s|@MRBS_LDAP_BASE_DN@|${MRBS_LDAP_BASE_DN}|" config.inc.php
sed -i "s|@MRBS_COMPANY@|${MRBS_COMPANY}|" config.inc.php
sed -i "s|@MRBS_DEFAULT_VIEW@|${MRBS_DEFAULT_VIEW}|" config.inc.php
sed -i "s|@MRBS_SMTP_HOST@|${MRBS_SMTP_HOST}|" config.inc.php
sed -i "s|@MRBS_ADMIN_EMAIL@|${MRBS_ADMIN_EMAIL}|" config.inc.php
sed -i "s|@MRBS_URL_BASE@|${MRBS_URL_BASE}|" config.inc.php
sed -i "s|@MRBS_EMAIL_FROM@|${MRBS_EMAIL_FROM}|" config.inc.php
sed -i "s|@MRBS_ENABLE_REGISTRATION@|${MRBS_ENABLE_REGISTRATION}|g" config.inc.php
sed -i "s|@MRBS_DENY_PUBLIC_ACCESS@|${MRBS_DENY_PUBLIC_ACCESS}|g" config.inc.php
sed -i "s|@MRBS_EMAIL_DOMAIN@|${MRBS_EMAIL_DOMAIN}|g" config.inc.php
sed -i "s|@MRBS_LDAP_CN@|${MRBS_LDAP_CN}|g" config.inc.php
sed -i "s|@MRBS_LDAP_PASS@|${MRBS_LDAP_PASS}|g" config.inc.php
sed -i "s|@MRBS_UNIPI_VALID_BASES@|${MRBS_UNIPI_VALID_BASES}|g" config.inc.php
sed -i "s|@MRBS_UNIPI_VALID_UIDS@|${MRBS_UNIPI_VALID_UIDS}|g" config.inc.php
sed -i "s|@MRBS_UNIPI_VALID_DEPARTMENTS@|${MRBS_UNIPI_VALID_DEPARTMENTS}|g" config.inc.php
sed -i "s|@MRBS_READONLY_ROOMS@|${MRBS_READONLY_ROOMS}|g" config.inc.php
sed -i "s|@MRBS_UNIPI_DM_VALID_ROLES@|${MRBS_UNIPI_DM_VALID_ROLES}|g" config.inc.php
sed -i "s|@MRBS_HIDDEN_DAYS@|${MRBS_HIDDEN_DAYS}|g" config.inc.php

if [ -f /tables.my.sql ]; then
  echo "Waiting for the DB to come up to setup the tables"
  while ! mysqladmin ping -h ${MRBS_DB_HOST} --silent; do
    sleep 1
    echo " ... waiting for db ... "
  done

  echo "show tables" | mysql --user=$MRBS_DB_USER --password=$MRBS_DB_PASSWORD --host=$MRBS_DB_HOST --database=$MRBS_DB_NAME | grep mrbs_area > /dev/null
  if [ $? -ne 0 ]; then
    mysql --user=$MRBS_DB_USER --password=$MRBS_DB_PASSWORD --host=$MRBS_DB_HOST --database=$MRBS_DB_NAME < /tables.my.sql
  fi
fi


# write the ping file
echo "SUCCESS" > ping


/usr/sbin/apache2ctl -D FOREGROUND
