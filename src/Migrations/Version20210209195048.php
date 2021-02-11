<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210209195048 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Use commercial ID in refund workflow instead of order ID.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE payment_mapping RENAME COLUMN mirakl_order_id TO mirakl_commercial_order_id');
        $this->addSql('ALTER TABLE stripe_refund ADD mirakl_commercial_order_id VARCHAR(255)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_refund DROP COLUMN mirakl_commercial_order_id');
        $this->addSql('ALTER TABLE payment_mapping RENAME COLUMN mirakl_commercial_order_id TO mirakl_order_id');
    }
}
