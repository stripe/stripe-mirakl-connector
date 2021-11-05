<?php

namespace App\Tests\Command;

use App\Entity\PaymentMapping;
use App\Repository\PaymentMappingRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PaymentValidationCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var PaymentMappingRepository
     */
    protected $paymentMappingRepository;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $validateReceiver;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $captureReceiver;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $cancelReceiver;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $this->command = $application->find('connector:validate:pending-debit');
        $this->commandTester = new CommandTester($this->command);

        $this->validateReceiver = self::$container->get('messenger.transport.validate_mirakl_order');
        $this->captureReceiver = self::$container->get('messenger.transport.capture_pending_payment');
        $this->cancelReceiver = self::$container->get('messenger.transport.cancel_pending_payment');

        $this->paymentMappingRepository = self::$container->get('doctrine')->getRepository(PaymentMapping::class);
    }

    private function executeCommand()
    {
        $this->validateReceiver->reset();
        $this->captureReceiver->reset();
        $this->cancelReceiver->reset();
        $this->commandTester->execute(['command' => $this->command->getName()]);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    private function mockPaymentMapping($commercialId, $paymentId, $amount)
    {
        $mapping = new PaymentMapping();
        $mapping->setMiraklCommercialOrderId($commercialId);
        $mapping->setStripeChargeId($paymentId);
        $mapping->setStripeAmount($amount);
        $mapping->setStatus(PaymentMapping::TO_CAPTURE);

        $this->paymentMappingRepository->persist($mapping);
        $this->paymentMappingRepository->flush();

        return $mapping;
    }

    public function testPendingValidationNoMapping()
    {
        // 2 pending debits but 0 mapping
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(0, $this->captureReceiver->getSent());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testPendingValidationWithMapping()
    {
        // 2 pending debits with a mapping
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_NONE_VALIDATED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            12345
        );
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_PARTIALLY_VALIDATED,
            StripeMock::PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE,
            12345
        );
        $this->executeCommand();
        $this->assertCount(1, $messages = $this->validateReceiver->getSent());
        $this->assertCount(2, $messages[0]->getMessage()->getOrders());
        $this->assertCount(0, $this->captureReceiver->getSent());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCaptureForNonExistingOrder()
    {
        // 1 payment mapped to an order not created yet
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_NOT_FOUND,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            8472
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(0, $this->captureReceiver->getSent());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCaptureFullAmount()
    {
        // 1 payment mapped to a fully validated order
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_ALL_VALIDATED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            16944
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(1, $messages = $this->captureReceiver->getSent());
        $this->assertEquals(16944, $messages[0]->getMessage()->getAmount());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCaptureIncludingOperatorAmount()
    {
        // 1 payment mapped to a fully validated order and amount > total
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_ALL_VALIDATED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            16944 + 1234 // 1234 from operator order
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(1, $messages = $this->captureReceiver->getSent());
        $this->assertEquals(16944 + 1234, $messages[0]->getMessage()->getAmount());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCaptureOperatorAmountOnly()
    {
        // 1 payment mapped to a fully canceled order
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_CANCELED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            8472 + 1234 // 1234 from operator order
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(1, $messages = $this->captureReceiver->getSent());
        $this->assertEquals(1234, $messages[0]->getMessage()->getAmount());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCapturePartialAmount()
    {
        // 1 payment mapped to a partially refused order
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_PARTIALLY_REFUSED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            16944
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(1, $messages = $this->captureReceiver->getSent());
        $this->assertEquals(8472, $messages[0]->getMessage()->getAmount());
        $this->assertCount(0, $this->cancelReceiver->getSent());
    }

    public function testCancelPayment()
    {
        // 1 payment mapped to a canceled order
        $this->mockPaymentMapping(
            MiraklMock::ORDER_COMMERCIAL_CANCELED,
            StripeMock::CHARGE_STATUS_AUTHORIZED,
            8472
        );
        $this->executeCommand();
        $this->assertCount(0, $this->validateReceiver->getSent());
        $this->assertCount(0, $this->captureReceiver->getSent());
        $this->assertCount(1, $this->cancelReceiver->getSent());
    }
}
