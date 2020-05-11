.PHONY: stylecheck staticanalysis

all: stylecheck staticanalysis doc

stylecheck:
	phpcs.phar --colors --standard=PSR12 --ignore=vendor/ .

staticanalysis:
	vendor/bin/psalm

doc:
	rm -r ~/www/carddavclient/*
	phpDocumentor.phar -d . -t ~/www/carddavclient --ignore "vendor/" --title="CardDAV Client Library"
