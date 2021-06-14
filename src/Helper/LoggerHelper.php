<?php

namespace App\Helper;

use App\Monolog\Handler\GrayLogHandler;
use App\Monolog\Processor\ExceptionProcessor;
use App\Monolog\Processor\UserProcessor;
use Doctrine\Common\Persistence\ManagerRegistry;
use Monolog\Logger as Monolog;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * Class LoggerHelper.
 */
class LoggerHelper
{
    /**
     * Constantes de error_level.
     */
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    public const ALERT = 'alert';
    public const EMERGENCY = 'emergency';

    /**
     * Canales disponibles.
     */
    public const CHANNEL_REQUEST = 'site';
    public const CHANNEL_USER = 'site';
    public const CHANNEL_USER_AUTH = 'site';
    public const CHANNEL_RESPONSE = 'site';
    public const CHANNEL_EXCEPTION = 'site';
    public const CHANNEL_ORDER = 'order';
    public const CHANNEL_SITE = 'site';

    /**
     * @var Security
     */
    private $security;

    /**
     * @var Request|null
     */
    private $request;

    /**
     * @var EnvVarProcessor
     */
    private $isDev;

    /**
     * LoggerHelper constructor.
     */
    public function __construct(
        Security $security,
        RequestStack $requestStack)
    {
        $this->security = $security;
        $this->request = $requestStack->getCurrentRequest();
        $this->isDev = 'dev' === $_ENV['APP_ENV'];
    }

    /**
     * @return Monolog
     */
    public function getLogger(string $channel = 'default')
    {
        $apiUrl = getenv('API_LOG');
        if($channel === 'default'){
            $channel = self::CHANNEL_SITE;
        }
        $logger = new Monolog($channel, [
            new GrayLogHandler($apiUrl, $this->isDev ? Monolog::DEBUG : Monolog::INFO, $this->isDev),
        ], [
            //new RequestProcessor($this->request),
            new ExceptionProcessor(),
        ]);

        return $logger;
    }
}