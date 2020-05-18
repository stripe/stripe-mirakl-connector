
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

package:
	tar --exclude='./.env' --exclude='./.env.test' --exclude='./tests' \
			--exclude='./.git' --exclude='./.gitignore' \
			--exclude='./bin/phpunit' --exclude='./phpunit.xml.dist' \
			--exclude='./bin/.phpunit' --exclude='./.phpunit.result.cache' \
	 		--exclude='./.php_cs.cache' --exclude='./var' --exclude='./vendor' \
	 		--exclude='./nginx_app.conf' --exclude='./var' --exclude='./vendor' \
	 		--exclude='./.travis.yml' --exclude='./.coveralls.yml' --exclude='./phpstan.neon' \
	 		--exclude='./config/packages/test' --exclude='./config/packages/dev' \
	 		--exclude='./config/routes/dev' --exclude='./config/packages/dev' \
	 		--exclude='./Makefile' --exclude='./Procfile' \
			-zcvf app.tar.gz .
