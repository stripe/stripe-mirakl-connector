<?php

namespace App\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;
use Throwable;

class ExceptionProcessor implements ProcessorInterface
{
    /**
     * @return array The processed records
     */
    public function __invoke(array $record)
    {
        foreach ($record['context'] as $index => $context) {
            if ($context instanceof Throwable) {
                $record['context'][$index] = [
                    'message' => $context->getMessage(),
                    'file' => $context->getFile(),
                    'line' => $context->getLine(),
                    'trace' => $context->getTrace(),
                ];
            }
        }

        return $record;
    }
}