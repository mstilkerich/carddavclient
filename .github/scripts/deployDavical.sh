#!/bin/bash

set -e

BASEDIR=/var/www/html/davical

sudo apt-get update
sudo apt-get install -y apache2 libapache2-mod-php7.4 postgresql-client libyaml-perl libdbd-pg-perl libdbi-perl php php-pgsql php-imap php-curl php-cgi php-xml

wget -O awl.tar.xz https://www.davical.org/downloads/awl_0.62.orig.tar.xz
wget -O davical.tar.xz https://www.davical.org/downloads/davical_1.1.10.orig.tar.xz

for dist in awl davical; do
	DISTDIR="$BASEDIR/$dist"
	sudo mkdir -p "$DISTDIR"
	sudo tar -xf $dist.tar.xz -C "$DISTDIR"
	sudo chown -R root:www-data "$DISTDIR"
	sudo find "$DISTDIR" -type d -exec chmod u=rwx,g=rx,o=rx '{}' \;
	sudo find "$DISTDIR" -type f -exec chmod u=rw,g=r,o=r '{}' \;
done

# Setup database
# The davical scripts assume they can just run the postgres CLI commands as user with full DB access
if [ -f "$HOME/.pgpass" ]; then
	echo "pgpass already exists - skipping"
else
	echo "*:*:*:${PGUSER}:postgres" > "$HOME/.pgpass"
	chmod 600 "$HOME/.pgpass"
fi

cd "$BASEDIR/davical/dba"
./create-database.sh davical admin postgres postgres

# Configure davical
sudo echo "127.0.0.1 davical.localdomain" >> /etc/hosts

sudo sed -e '1i <VirtualHost *:80>' \
	-e "s,/usr/share/,$BASEDIR/,g" \
	-e '$ a </VirtualHost>' \
	"$BASEDIR/davical/config/apache-davical.conf" >/etc/apache2/sites-available/davical.conf
sudo a2ensite davical
sudo systemctl restart apache2

sudo cat << EOF >"$BASEDIR/davical/config/config.php"
<?php
	\$c->domain_name = "davical.localdomain";
	\$c->sysabbr     = 'DAViCal';
	\$c->system_name = "DAViCal Server";
	\$c->pg_connect[] = 'dbname=davical host=$PGHOST port=5432 user=$PGUSER password=postgres';
EOF

