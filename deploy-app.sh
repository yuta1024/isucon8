#!/bin/sh

set -eu

# app
sudo cp -r {*.php,lib,views} /home/isucon/torb/webapp/php/

# db
sudo cp ./db/* /home/isucon/torb/db/

# nginx
sudo cp ./infra/nginx/nginx.conf /etc/nginx/nginx.conf

# fpm
sudo cp ./infra/fpm/php-fpm.conf /etc/php-fpm.conf
sudo cp ./infra/fpm/php-fpm.d/www.conf /etc/php-fpm.d/www.conf
sudo mkdir -p /run/php
sudo chmod 777 /run/php
sudo chown -R root:isucon /var/lib/php

# php
sudo cp ./infra/php/php.ini /etc/php.ini

# restart
sudo systemctl restart nginx
sudo systemctl restart php-fpm
