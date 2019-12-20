<?php

namespace App\Message;

interface NotifiableMessageInterface
{
    public function getContent(): array;
}
