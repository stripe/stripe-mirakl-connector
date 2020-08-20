<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\PathException;

require dirname(__DIR__).'/vendor/autoload.php';

// Load cached env vars if the .env.local.php file exists
// Run "composer dump-env prod" to create it (requires symfony/flex >=1.2)
if (is_array($env = @include dirname(__DIR__).'/.env.local.php')) {
    foreach ($env as $k => $v) {
        $_ENV[$k] = $_ENV[$k] ?? (isset($_SERVER[$k]) && 0 !== strpos($k, 'HTTP_') ? $_SERVER[$k] : $v);
    }
} elseif (!class_exists(Dotenv::class)) {
    throw new RuntimeException('Please run "composer require symfony/dotenv" to load the ".env" files configuring the application.');
} else {
    // load all the .env files
    try {
        (new Dotenv(false))->loadEnv(dirname(__DIR__).'/.env');
    } catch (PathException $e) {
        // Missing .env file. Ignore
    }
}

$_SERVER += $_ENV;
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) ?: 'dev';
$_SERVER['APP_DEBUG'] = $_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? 'prod' !== $_SERVER['APP_ENV'];
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = (int) $_SERVER['APP_DEBUG'] || filter_var($_SERVER['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';


// Insure backward compatibility of deprecated environment variable names
$deprecatedVarNames = [
  // Old => New
  'BASE_HOST' => 'SMC_URL',
  'DATABASE_URL' => 'SMC_DATABASE',
  'MESSENGER_TRANSPORT_DSN' => 'SMC_MESSENGER',
  'MAILER_DSN' => 'SMC_MAILER',
  'TECHNICAL_ALERT_EMAIL' => 'SMC_MAILER_TO',
  'TECHNICAL_ALERT_EMAIL_FROM' => 'SMC_MAILER_FROM',
  'STRIPE_CLIENT_ID' => 'SMC_STRIPE_CLIENT_ID',
  'STRIPE_CLIENT_SECRET' => 'SMC_STRIPE_API_KEY',
  'STRIPE_WEBHOOK_SECRET' => 'SMC_STRIPE_WEBHOOK_SECRET',
  'ENABLES_AUTOMATIC_TRANSFER_CREATION' => 'SMC_ENABLE_TRANSFERS',
  'ENABLES_AUTOMATIC_REFUND_CREATION' => 'SMC_ENABLE_REFUNDS',
  'OPERATOR_PASSWORD' => 'SMC_API_SECRET_KEY',
  'OPERATOR_NOTIFICATION_URL' => 'SMC_WEBHOOK_URL',
  'MAIL_ON_NOTIFICATION_ENDPOINT_DOWN' => 'SMC_WEBHOOK_ENABLE_ALERTS',
  'MAIL_ON_NOTIFICATION_ENDPOINT_DOWN_COOLDOWN' => 'SMC_WEBHOOK_ALERTS_DELAY',
  'MIRAKL_HOST_NAME' => 'SMC_MIRAKL_URL',
  'MIRAKL_API_KEY' => 'SMC_MIRAKL_API_KEY',
  'MIRAKL_CUSTOM_FIELD_CODE' => 'SMC_KYC_MIRAKL_CUSTOM_FIELD',
  'REDIRECT_ONBOARDING' => 'SMC_KYC_REDIRECT_URL',
  'STRIPE_PREFILL_ONBOARDING' => 'SMC_KYC_PREFILL_FORM'
];
$message = 'Deprecated environment variable "%s", please use "%s" instead.';
foreach ($_ENV as $k => $v) {
    if (isset($deprecatedVarNames[$k])) {
        $newK = $deprecatedVarNames[$k];
        $_SERVER[$newK] = $_ENV[$newK] = $v;
        unset($_ENV[$k]);

        trigger_error(sprintf($message, $k, $newK), E_USER_DEPRECATED);
    }
}
