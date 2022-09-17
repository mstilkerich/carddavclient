DOCDIR := doc/api/

# Set some options on Github actions
ifeq ($(CI),true)
PSALM_XOPTIONS=--shepherd --no-progress --no-cache
endif

.PHONY: all stylecheck phpcompatcheck staticanalyses psalmanalysis doc tests verification

all: staticanalyses doc

verification: staticanalyses tests

staticanalyses: stylecheck phpcompatcheck psalmanalysis

stylecheck:
	vendor/bin/phpcs --colors --standard=PSR12 src/ tests/

phpcompatcheck:
	vendor/bin/phpcs --colors --standard=PHPCompatibility --runtime-set testVersion 7.1 src/ tests/

psalmanalysis: tests/Interop/AccountData.php
	vendor/bin/psalm --threads=8 --no-cache --report=testreports/psalm.txt --report-show-info=true --no-diff $(PSALM_XOPTIONS)

tests: tests-interop unittests
	vendor/bin/phpcov merge --html testreports/coverage testreports

.PHONY: unittests
unittests: tests/Unit/phpunit.xml
	@echo
	@echo  ==========================================================
	@echo "                   EXECUTING UNIT TESTS"
	@echo  ==========================================================
	@echo
	@mkdir -p testreports/unit
	vendor/bin/phpunit -c tests/Unit/phpunit.xml

.PHONY: tests-interop
tests-interop: tests/Interop/phpunit.xml tests/Interop/AccountData.php
	@echo
	@echo  ==========================================================
	@echo "       EXECUTING CARDDAV INTEROPERABILITY TESTS"
	@echo  ==========================================================
	@echo
	@mkdir -p testreports/interop
	vendor/bin/phpunit -c tests/Interop/phpunit.xml

doc:
	rm -rf $(DOCDIR)
	phpDocumentor.phar -d src/ -t $(DOCDIR) --title="CardDAV Client Library" --setting=graphs.enabled=true --validate
	[ -d ../carddavclient-pages ] && rsync -r --delete --exclude .git doc/api/ ../carddavclient-pages

# For github CI system - if AccountData.php is not available, create from AccountData.php.dist
tests/Interop/AccountData.php: | tests/Interop/AccountData.php.dist
	cp $| $@

.PHONY: codecov-upload
codecov-upload:
	if [ -n "$$CODECOV_TOKEN" ]; then \
		curl -s https://codecov.io/bash >testreports/codecov.sh; \
		bash testreports/codecov.sh -F unittests -f testreports/unit/clover.xml -n 'Carddavclient unit test coverage'; \
		bash testreports/codecov.sh -F interop -f testreports/interop/clover.xml -n 'Carddavclient interoperability test coverage'; \
	else \
		echo "Error: Set CODECOV_TOKEN environment variable first"; \
		exit 1; \
	fi

