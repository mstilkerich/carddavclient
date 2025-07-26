#!/bin/sh

set -e

TEMPFILE=$(mktemp)
COOKIES=$(mktemp)
trap 'rm -f -- "$TEMPFILE" "$COOKIES"' EXIT

BAIKALURL="${1:-http://localhost:8080}"

# Get Form for CSRF_TOKEN
curl -v -L \
	-c "$COOKIES" \
	$BAIKALURL/admin/install/ \
	-o "$TEMPFILE"

# System settings
CSRF_TOKEN=$(grep -oP 'input.*name="CSRF_TOKEN" value="\K[^"]+' "$TEMPFILE")
curl -v -L \
	-c "$COOKIES" -b "$COOKIES" \
	-d "CSRF_TOKEN=$CSRF_TOKEN" \
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
	$BAIKALURL/admin/install/ \
	-o "$TEMPFILE"

# Database setup
CSRF_TOKEN=$(grep -oP 'input.*name="CSRF_TOKEN" value="\K[^"]+' "$TEMPFILE")
curl -v -L  \
	-c "$COOKIES" -b "$COOKIES" \
	-d "CSRF_TOKEN=$CSRF_TOKEN" \
	-d 'Baikal_Model_Config_Database::submitted=1' \
	-d 'refreshed=0' \
	-d 'data[backend]=sqlite' \
	-d 'witness[backend]=1' \
	-d 'data[sqlite_file]=/var/www/baikal/Specific/db/db.sqlite' \
	-d 'witness[sqlite_file]=1' \
	$BAIKALURL/admin/install/ \
	-o "$TEMPFILE"
