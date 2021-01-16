DOCDIR := doc/api/
.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc tests verification

all: staticanalyses doc

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 src/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/ tests/

psalmanalysis: tests/AccountData.php
	vendor/bin/psalm --no-cache --shepherd --report=testreports/psalm.txt --report-show-info=true --no-progress

doc:
	rm -rf $(DOCDIR)
	phpDocumentor.phar -d src/ -t $(DOCDIR) --title="CardDAV Client Library"

tests:
	@[ -f tests/AccountData.php ] || (echo "Create tests/AccountData.php from template tests/AccountData.php.dist to execute tests"; exit 1)
	vendor/bin/phpunit

# For github CI system - if AccountData.php is not available, create from AccountData.php.dist
tests/AccountData.php: | tests/AccountData.php.dist
	cp $| $@
