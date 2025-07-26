#!/bin/sh

set -e

TEMPFILE=$(mktemp)
COOKIES=$(mktemp)
trap 'rm -f -- "$TEMPFILE" "$COOKIES"' EXIT

BAIKALURL="${1:-http://localhost:8080}"
NUSER="${2:-citest}"
NPASS="${3:-citest}"
NDISP="${4:-citest}"
NMAIL="${5:-citest@example.com}"

# Login
curl -v -L -X POST\
	-c "$COOKIES" \
	-d 'auth=1' \
	-d 'login=admin' \
	-d 'password=admin' \
	$BAIKALURL/admin/ \
	-o "$TEMPFILE"

# Get Form for CSRF_TOKEN
curl -v -L \
	-c "$COOKIES" -b "$COOKIES" \
	"$BAIKALURL/admin/?/users/new/1/#form" \
	-o "$TEMPFILE"

# Create User
CSRF_TOKEN=$(grep -oP 'input.*name="CSRF_TOKEN" value="\K[^"]+' "$TEMPFILE")
curl -v -L -X POST \
	-c "$COOKIES" -b "$COOKIES" \
	-d "CSRF_TOKEN=$CSRF_TOKEN" \
	-d 'Baikal_Model_User::submitted=1' \
	-d 'refreshed=0' \
	-d "data[username]=$NUSER" \
	-d "witness[username]=1" \
	-d "data[displayname]=$NDISP" \
	-d "witness[displayname]=1" \
	-d "data[email]=$NMAIL" \
	-d "witness[email]=1" \
	-d "data[password]=$NPASS" \
	-d "witness[password]=1" \
	-d "data[passwordconfirm]=$NPASS" \
	-d "witness[passwordconfirm]=1" \
	"$BAIKALURL/admin/?/users/new/1/#form" \
	-o "$TEMPFILE"
