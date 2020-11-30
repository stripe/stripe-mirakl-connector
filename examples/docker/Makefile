
install:
	docker-compose up -d

db-install:
	docker-compose run --rm app bin/console doctrine:migration:migrate --no-interaction
