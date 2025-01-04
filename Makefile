.PHONY: all
all: dev

.PHONY: install
install:
	composer install

.PHONY: dev
dev: install
	php -S 127.0.0.1:8000 -t public

.PHONY: test
test:
	./vendor/bin/pest
