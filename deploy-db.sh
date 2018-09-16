#!/bin/sh

set -eu

if [ `hostname` != '118-27-6-207' ]; then
  echo "abort: This server is not DB server!"
  exit 1
fi

# nginx
sudo cp ./infra/nginx/nginx-lb.conf /etc/nginx/nginx.conf

# mysql
sudo cp ./infra/mysql/my.cnf /etc/my.cnf

# restart
sudo systemctl restart nginx
sudo systemctl restart mysql
