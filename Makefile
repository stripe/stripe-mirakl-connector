DOCKER_COMPOSE_RUN ?= docker-compose run --rm
DOCKER_COMPOSE_RUN_PHP ?= $(DOCKER_COMPOSE_RUN) php
DOCKER_COMPOSE_RUN_TEST_PHP ?= $(DOCKER_COMPOSE_RUN) -e APP_ENV=test php

docker/nginx/certs/localhost.pem: certificates
docker/nginx/certs/localhost-key.pem: certificates

mkcert: 
	brew install nss mkcert
	mkcert -install

certificates: mkcert
	mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost

install:
	docker-compose up -d

test: phpstan php-cs-fixer-check phpunit

db-reset:
	$(DOCKER_COMPOSE_RUN_PHP) bin/console d:d:d --force
	$(DOCKER_COMPOSE_RUN_PHP) bin/console d:d:c
	$(DOCKER_COMPOSE_RUN_PHP) bin/console d:s:c
	$(DOCKER_COMPOSE_RUN_PHP) bin/console hautelook:fixtures:load --no-interaction

phpunit-integration:
	$(DOCKER_COMPOSE_RUN_TEST_PHP) ./bin/phpunit --filter integration

phpunit-fast:
	$(DOCKER_COMPOSE_RUN_TEST_PHP) ./bin/phpunit --exclude-group integration

phpunit:
	$(DOCKER_COMPOSE_RUN_TEST_PHP) ./bin/phpunit

.PHONY: coverage
coverage:
	$(DOCKER_COMPOSE_RUN_TEST_PHP) ./bin/phpunit --coverage-html=coverage

phpstan:
	./vendor/bin/phpstan analyse src --level=max

php-cs-fixer:
	./vendor/bin/php-cs-fixer --ansi fix src -vvv

php-cs-fixer-check:
	./vendor/bin/php-cs-fixer --ansi fix src -vvv --dry-run

ci-phpunit:
	./bin/phpunit

transfer-mail:
	$(DOCKER_COMPOSE_RUN_PHP) bin/console connector:notify:failed-operation

vendor: composer.json
	composer install -n --prefer-dist
