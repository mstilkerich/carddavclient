.PHONY: stylecheck staticanalysis

all: stylecheck staticanalysis doc

stylecheck:
	phpcs.phar --colors --standard=PSR12 src/

staticanalysis:
	vendor/bin/psalm

doc:
	rm -r ~/www/carddavclient/*
	#phpDocumentor.phar -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
	../phpdocumentor/bin/phpdoc -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
