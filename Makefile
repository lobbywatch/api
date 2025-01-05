.PHONY: all
all: dev

.PHONY: install
install:
	php composer.phar install

.PHONY: dev
dev: install
	php -S 127.0.0.1:8000 -t public

.PHONY: test
test:
	./vendor/bin/pest

.PHONY: test-rebuild
test-rebuild:
	./vendor/bin/pest --update-snapshots

.PHONY: build
build:
	php composer.phar install --no-dev
	rm -rf ./build
	mkdir build
	cp bootstrap.php build
	cp -R public build
	cp -R src build
	cp -R vendor build
