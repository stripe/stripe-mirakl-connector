<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210209195048 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Rename payment_mapping.mirakl_order_id to payment_mapping.mirakl_commercial_order_id.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE payment_mapping RENAME COLUMN mirakl_order_id TO mirakl_commercial_order_id');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE payment_mapping RENAME COLUMN mirakl_commercial_order_id TO mirakl_order_id');
    }
}
