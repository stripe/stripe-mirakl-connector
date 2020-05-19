
test: phpstan php-cs-fixer-check ci-phpunit

phpstan:
	./vendor/bin/phpstan analyse src --level=max

php-cs-fixer:
	./vendor/bin/php-cs-fixer --ansi fix src -vvv

php-cs-fixer-check:
	./vendor/bin/php-cs-fixer --ansi fix src -vvv --dry-run

ci-phpunit:
	./bin/phpunit

vendor: composer.json
	composer install -n --prefer-dist
