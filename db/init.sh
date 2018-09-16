#!/bin/bash

ROOT_DIR=$(cd $(dirname $0)/..; pwd)
DB_DIR="$ROOT_DIR/db"
BENCH_DIR="$ROOT_DIR/bench"

export MYSQL_PWD=isucon

mysql -h172.17.119.2 -uisucon -e "DROP DATABASE IF EXISTS torb; CREATE DATABASE torb;"
mysql -h172.17.119.2 -uisucon torb < "$DB_DIR/schema.sql"

if [ ! -f "$DB_DIR/isucon8q-initial-dataset.sql.gz" ]; then
  echo "Run the following command beforehand." 1>&2
  echo "$ ( cd \"$BENCH_DIR\" && bin/gen-initial-dataset )" 1>&2
  exit 1
fi

mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations DROP KEY event_id_and_sheet_id_idx'
gzip -dc "$DB_DIR/isucon8q-initial-dataset.sql.gz" | mysql -h172.17.119.2 -uisucon torb
mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY event_id_and_sheet_id_idx (event_id, sheet_id)'
mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY user_id_idx (user_id)'

mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD last_updated DATETIME(6) NOT NULL DEFAULT "1970-01-01 00:00:00"'
mysql -h172.17.119.2 -uisucon torb -e 'UPDATE reservations SET last_updated = IFNULL(canceled_at, reserved_at)'
mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY last_updated_idx (last_updated)'

mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD canceled INT NOT NULL DEFAULT 0'
mysql -h172.17.119.2 -uisucon torb -e 'UPDATE reservations SET canceled = 1 WHERE canceled_at IS NOT NULL'
mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY canceled_idx (canceled)'

mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY user_id_canceled_idx (user_id, canceled)'
mysql -h172.17.119.2 -uisucon torb -e 'ALTER TABLE reservations ADD KEY event_id_and_canceled_idx (event_id, canceled)'
