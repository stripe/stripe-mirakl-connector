DOCKER_COMPOSE_RUN ?= docker-compose run --rm
DOCKER_COMPOSE_RUN_APP ?= $(DOCKER_COMPOSE_RUN) app

mkcert:
	brew install nss mkcert
	mkcert -install

certificates:
	mkcert -cert-file docker/app/certs/localhost.pem -key-file docker/app/certs/localhost-key.pem localhost

install:
	docker-compose up -d

db-install:
	$(DOCKER_COMPOSE_RUN_APP) bin/console doctrine:migration:migrate --no-interaction

db-reset:
	$(DOCKER_COMPOSE_RUN_APP) bin/console d:d:d --force
	$(DOCKER_COMPOSE_RUN_APP) bin/console d:d:c
	$(DOCKER_COMPOSE_RUN_APP) bin/console d:s:c
	$(DOCKER_COMPOSE_RUN_APP) bin/console hautelook:fixtures:load --no-interaction

transfer-mail:
	$(DOCKER_COMPOSE_RUN_APP) bin/console connector:notify:failed-operation
