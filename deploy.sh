#!/bin/sh

set -eu

sudo cp -r {*.php,lib,views} /home/isucon/torb/webapp/php/

# nginx
sudo cp ./infra/nginx/nginx.conf /etc/nginx/nginx.conf

# mysql
sudo cp ./infra/mysql/my.cnf /etc/my.cnf
