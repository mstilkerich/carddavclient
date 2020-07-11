.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc tests verification

all: stylecheck staticanalysis doc

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis phpstan

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 src/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/ tests/

psalmanalysis:
	vendor/bin/psalm

phpstan:
	vendor/bin/phpstan analyse

doc:
	rm -r ~/www/carddavclient/*
	#phpDocumentor.phar -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 
	../phpdocumentor/bin/phpdoc -d src/ -t ~/www/carddavclient --title="CardDAV Client Library" 

tests:
	@[ -f tests/AccountData.php ] || (echo "Create tests/AccountData.php from template tests/AccountData.php.dist to execute tests"; exit 1)
	vendor/bin/phpunit

