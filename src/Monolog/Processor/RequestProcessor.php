<?php

namespace App\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

class RequestProcessor implements ProcessorInterface
{
    /**
     * @var Request
     */
    private $request;

    /**
     * RequestProcessor constructor.
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @return array The processed records
     */
    public function __invoke(array $record)
    {
        if ($this->request instanceof Request) {
            $record['context']['request'] = [];
            $record['context']['request']['ip'] = $this->request->getClientIp();
            $record['context']['request']['uri'] = $this->request->getSchemeAndHttpHost().$this->request->getRequestUri();
            $record['context']['request']['method'] = $this->request->getMethod();
            $record['context']['request']['content_type'] = $this->request->getContentType();
            $record['context']['request']['content'] = $this->request->getContent();
        }

        return $record;
    }
}