#!/bin/sh

set -e

/usr/local/bin/dumpcerts.sh

php bin/console doctrine:migrations:migrate --no-interaction
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
php-fpm

