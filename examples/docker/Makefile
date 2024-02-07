
install:
	docker-compose up -d

db-install:
	docker-compose run --rm php bin/console doctrine:migration:migrate --no-interaction
