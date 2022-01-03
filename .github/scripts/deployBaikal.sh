#!/bin/sh

set -e

BAIKALURL=http://localhost:8080

# Web Setup
curl -v -X POST \
	-d 'Baikal_Model_Config_Standard::submitted=1' \
	-d 'refreshed=0' \
	-d 'data[timezone]=Europe/Berlin' \
	-d 'witness[timezone]=1' \
	-d 'data[card_enabled]=1' \
	-d 'witness[card_enabled]=1' \
	-d 'witness[cal_enabled]=1' \
	-d 'data[invite_from]=noreply@localhost' \
	-d 'witness[invite_from]=1' \
	-d 'data[dav_auth_type]=Digest' \
	-d 'witness[dav_auth_type]=1' \
	-d 'data[admin_passwordhash]=admin' \
	-d 'witness[admin_passwordhash]=1' \
	-d 'data[admin_passwordhash_confirm]=admin' \
	-d 'witness[admin_passwordhash_confirm]=1' \
	$BAIKALURL/admin/install/


curl -v -X POST \
	-d 'Baikal_Model_Config_Database::submitted=1' \
	-d 'refreshed=0' \
	-d 'data[sqlite_file]=/var/www/baikal/Specific/db/db.sqlite' \
	-d 'witness[sqlite_file]=1' \
	-d 'witness[mysql]=1' \
	$BAIKALURL/admin/install/

# Add test user and addressbook to database
docker exec baikal sh -c 'apt-get update && apt-get install -y sqlite3'
cat .github/configs/baikal/initdb.sql | docker exec -i baikal sqlite3 /var/www/baikal/Specific/db/db.sqlite

