#!/bin/sh

set -e

php bin/console doctrine:migrations:migrate --no-interaction
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
php-fpm