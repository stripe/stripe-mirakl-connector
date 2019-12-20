web: heroku-php-nginx -C nginx_app.conf public/

worker: php bin/console messenger:consume operator_http_notification update_login_link process_transfers process_payouts --time-limit=3600 --env=prod
