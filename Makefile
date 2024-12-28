.PHONY: all
all: server

.PHONY: install
install:
	composer install

.PHONY: dev
dev:
	php -S 127.0.0.1:8000 -t public
