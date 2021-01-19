DOCDIR := doc/api/
.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc tests verification

all: staticanalyses doc

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 src/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/ tests/

psalmanalysis:
	vendor/bin/psalm --no-cache --shepherd --report=testreports/psalm.txt --report-show-info=true --no-progress

tests: tests-interop unittests
	vendor/bin/phpcov merge --html testreports/coverage testreports

.PHONY: unittests
unittests: tests/unit/phpunit.xml
	@echo
	@echo  ==========================================================
	@echo "                   EXECUTING UNIT TESTS"
	@echo  ==========================================================
	@echo
	vendor/bin/phpunit -c tests/unit/phpunit.xml

.PHONY: tests-interop
tests-interop: tests/interop/phpunit.xml
	@echo
	@echo  ==========================================================
	@echo "       EXECUTING CARDDAV INTEROPERABILITY TESTS"
	@echo  ==========================================================
	@echo
	vendor/bin/phpunit -c tests/interop/phpunit.xml

doc:
	rm -rf $(DOCDIR)
	phpDocumentor.phar -d src/ -t $(DOCDIR) --title="CardDAV Client Library"

