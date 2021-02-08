<?php

namespace App\Tests;

use App\Security\TokenAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ConnectorWebTestCase extends WebTestCase
{
    protected const TEST_OPERATOR_PASSWORD = 'operator-test';

    protected static function createUnauthenticatedClient(array $options = [], array $server = [])
    {
        return parent::createClient($options, $server);
    }

    protected static function createClient(array $options = [], array $server = [])
    {
        return parent::createClient($options, array_merge($server, [
            sprintf('HTTP_%s', TokenAuthenticator::AUTH_HEADER_NAME) => self::TEST_OPERATOR_PASSWORD,
        ]));
    }
}
