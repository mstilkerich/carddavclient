#!/bin/bash

ABOOKURL="$1"
ABOOKDISP="$2"
ABOOKDESC="$3"

if [ -z "$ABOOKURL" ]; then
    echo "Usage: $0 abookUrl abookDisplayname [abookDesc] [user] [password]"
    exit 1;
fi

AUTH=""
if [ -n "$4" ]; then
    AUTH="-u $4"
    if [ -n "$5" ]; then
        AUTH="$AUTH:$5"
    fi
fi

if [ -n "$ABOOKDISP" ]; then
    ABOOKDISP="<displayname>${ABOOKDISP}</displayname>"
fi
if [ -n "$ABOOKDESC" ]; then
    ABOOKDESC="<CARD:addressbook-description>${ABOOKDESC}</CARD:addressbook-description>"
fi

curl --no-progress-meter $AUTH -X MKCOL "$ABOOKURL" -v \
	-H 'Content-Type: application/xml' \
	--data \
"<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<mkcol xmlns=\"DAV:\" xmlns:CARD=\"urn:ietf:params:xml:ns:carddav\">
  <set>
    <prop>
      <resourcetype>
        <collection />
        <CARD:addressbook />
      </resourcetype>
      $ABOOKDISP
      $ABOOKDESC
    </prop>
  </set>
</mkcol>"
