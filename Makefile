DOCDIR := doc/api/
.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc tests verification

all: staticanalyses doc

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis phpstan

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 src/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/ tests/

psalmanalysis:
	vendor/bin/psalm --no-cache

phpstan:
	vendor/bin/phpstan analyse

doc:
	rm -rf $(DOCDIR)
	phpDocumentor.phar -d src/ -t $(DOCDIR) --title="CardDAV Client Library"

tests:
	@[ -f tests/AccountData.php ] || (echo "Create tests/AccountData.php from template tests/AccountData.php.dist to execute tests"; exit 1)
	vendor/bin/phpunit

