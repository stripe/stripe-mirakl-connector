<?php

namespace App\Monolog\Processor;

use Monolog\Processor\ProcessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProcessor implements ProcessorInterface
{
    /**
     * @var UserInterface
     */
    private $user;

    /**
     * UserProcessor constructor.
     */
    public function __construct(UserInterface $user)
    {
        $this->user = $user;
    }

    /**
     * @return array The processed record
     */
    public function __invoke(array $record)
    {
        if ($this->user) {
            $record['context']['username'] = $this->user->getUsername();
        }

        return $record;
    }
}