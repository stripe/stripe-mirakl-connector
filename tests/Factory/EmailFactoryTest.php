<?php

namespace App\Tests\Controller;

use App\Factory\EmailFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;

class EmailFactoryTest extends TestCase
{
    private const TEST_FROM_EMAIL = 'hello+from@test.com';
    private const TEST_TO_EMAIL = 'hello+to@test.com';
    private const TEST_SUBJECT = 'Hello world';

    /**
     * @var EmailFactory
     */
    private $emailFactory;

    protected function setUp(): void
    {
        $this->emailFactory = new EmailFactory(self::TEST_FROM_EMAIL, self::TEST_TO_EMAIL, self::TEST_SUBJECT);
    }

    public function testCreateMessage()
    {
        $email = $this->emailFactory->createMessage();

        $this->assertCount(1, $email->getFrom());
        $this->assertEquals(new Address(self::TEST_FROM_EMAIL), $email->getFrom()[0]);

        $this->assertCount(1, $email->getTo());
        $this->assertEquals(new Address(self::TEST_TO_EMAIL), $email->getTo()[0]);

        $this->assertEquals(self::TEST_SUBJECT, $email->getSubject());
    }
}
