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
    ABOOKDESC="<CR:addressbook-description>${ABOOKDESC}</CR:addressbook-description>"
fi

curl --no-progress-meter $AUTH -X MKCOL "$ABOOKURL" --data \
"<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
<create xmlns=\"DAV:\" xmlns:CR=\"urn:ietf:params:xml:ns:carddav\">
  <set>
    <prop>
      <resourcetype>
        <collection />
        <CR:addressbook />
      </resourcetype>
      $ABOOKDISP
      $ABOOKDESC
    </prop>
  </set>
</create>"
