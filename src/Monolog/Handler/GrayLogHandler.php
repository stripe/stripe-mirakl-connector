<?php

namespace App\Monolog\Handler;

use Curl\Curl;
use ErrorException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class GrayLogHandler extends AbstractProcessingHandler
{
    private $apiLog;

    private $isDev;

    /**
     * RocketChatHandler constructor.
     *
     * @param int  $level
     * @param bool $isDev
     * @param bool $bubble
     */
    public function __construct(string $apiLog, $level = Logger::DEBUG, $isDev = true, $bubble = true)
    {
        $this->apiLog = $apiLog;
        $this->isDev = $isDev;
        parent::__construct($level, $bubble);
    }

    /**
     * Writes the record down to the log of the implementing handler.
     *
     * @param  $record []
     */
    public function write(array $record): void
    {
        $source = 'stripeConnector';
        $source = $this->isDev ? 'test'.$source : $source;
        if (!empty($record['context']['extra']) && is_array($record['context']['extra'])) {
            $record['context']['extra'] = json_encode($record['context']['extra']);
        }
        $payload_data = json_encode([
            'source' => $source,
            'channel' => $record['channel'],
            'level' => strtolower($record['level_name']),
            'message' => $record['message'],
            'context' => $record['context'],
        ]);

        $curl = new Curl();
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_TIMEOUT, 10);
        $curl->setUrl($this->apiLog);
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setHeader('Content-Length', strlen($payload_data));
        $curl->post('', $payload_data);
        if($curl->getErrorCode() > 0){
            echo($curl->getCurlErrorMessage(). 'data sent: '. json_encode($payload_data));
        }
    }
}
