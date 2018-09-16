#!/bin/sh

set -eu

# nginx
sudo cp ./infra/nginx/nginx-lb.conf /etc/nginx/nginx.conf

# mysql
sudo cp ./infra/mysql/my.cnf /etc/my.cnf

# restart
sudo systemctl restart nginx
sudo systemctl restart mysql
