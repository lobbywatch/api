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

.PHONY: deploy
deploy:
	rsync -vh build/bootstrap.php lobbywat@s034.cyon.net:/home/lobbywat/public_html/data
	rsync -rvh --delete build/public/ lobbywat@s034.cyon.net:/home/lobbywat/public_html/data/public
	rsync -rvh --delete build/src/ lobbywat@s034.cyon.net:/home/lobbywat/public_html/data/src
	rsync -rvh --delete build/vendor/ lobbywat@s034.cyon.net:/home/lobbywat/public_html/data/vendor
