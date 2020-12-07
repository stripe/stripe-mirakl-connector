<?php

namespace DoctrineMigrations;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\LoggerInterface;

class MigrationFactory implements \Doctrine\Migrations\Version\MigrationFactory
{
    /** @var Connection */
    private $connection;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @var StripeChargeRepository
     */
    private $stripeChargeRepository;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(
      Connection $connection,
      LoggerInterface $logger,
      StripeChargeRepository $stripeChargeRepository,
      StripeProxy $stripeProxy
    ) {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->service = $service;
        $this->stripeChargeRepository = $stripeChargeRepository;
        $this->stripeProxy = $stripeProxy;
    }

    public function createVersion(string $migrationClassName) : AbstractMigration
    {
        $migration = new $migrationClassName(
            $this->connection,
            $this->logger
        );

        $migration->setServices($this->stripeChargeRepository, $this->stripeProxy);

        return $migration;
    }
}
