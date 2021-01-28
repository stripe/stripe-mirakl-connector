<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20201601191812 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add services.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_refund ADD COLUMN type VARCHAR(255) NOT NULL DEFAULT \'REFUND_PRODUCT_ORDER\'');

        $this->addSql("UPDATE stripe_transfer SET type = 'TRANSFER_PRODUCT_ORDER' WHERE type = 'TRANSFER_ORDER'");
        $this->addSql("UPDATE config SET key = 'product_payment_split_checkpoint' WHERE key = 'process_transfer_checkpoint'");
        $this->addSql("UPDATE config SET key = 'seller_settlement_checkpoint' WHERE key = 'process_payout_checkpoint'");
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql("UPDATE stripe_transfer SET type = 'TRANSFER_ORDER' WHERE type = 'TRANSFER_PRODUCT_ORDER'");

        $this->addSql('ALTER TABLE stripe_refund DROP COLUMN type');
    }
}
