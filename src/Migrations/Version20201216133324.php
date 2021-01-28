<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20201216133324 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Adding config table.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE config_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE config (id INT NOT NULL, key VARCHAR(255) NOT NULL, value VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_84CED2B33EFA2266 ON config (key)');

        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN failed_reason TO status_reason');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN mirakl_update_time TO mirakl_created_date');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN stripe_payout_id TO payout_id');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN amount DROP NOT NULL');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN currency DROP NOT NULL');

        $this->addSql('ALTER TABLE stripe_refund RENAME COLUMN failed_reason TO status_reason');
        $this->addSql('ALTER TABLE stripe_refund DROP COLUMN commission');
        $this->addSql('ALTER TABLE stripe_refund DROP COLUMN stripe_reversal_id');
        $this->addSql('ALTER TABLE stripe_refund ADD COLUMN transaction_id VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN failed_reason TO status_reason');
        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN mirakl_update_time TO mirakl_created_date');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN mirakl_created_date DROP NOT NULL');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN amount DROP NOT NULL');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN currency DROP NOT NULL');

        $this->addSql("UPDATE stripe_transfer SET status = 'TRANSFER_ABORTED' WHERE status = 'TRANSFER_INVALID_AMOUNT'");

        $this->addSql('ALTER TABLE stripe_charge RENAME TO payment_mapping');
        $this->addSql('ALTER SEQUENCE IF EXISTS stripe_charge_id_seq RENAME TO payment_mapping_id_seq;');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER SEQUENCE IF EXISTS payment_mapping_id_seq RENAME TO stripe_charge_id_seq;');
        $this->addSql('ALTER TABLE payment_mapping RENAME TO stripe_charge');

        $this->addSql("UPDATE stripe_transfer SET status = 'TRANSFER_FAILED' WHERE status = 'TRANSFER_ABORTED'");
        $this->addSql("UPDATE stripe_transfer SET status = 'TRANSFER_FAILED' WHERE status = 'TRANSFER_ON_HOLD'");

        $this->addSql('UPDATE stripe_transfer SET currency = \'eur\' WHERE currency IS NULL');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN currency SET DEFAULT \'eur\'');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN currency SET NOT NULL');
        $this->addSql('UPDATE stripe_transfer SET amount = 0 WHERE amount IS NULL');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN amount SET DEFAULT 0');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN amount SET NOT NULL');
        $this->addSql('UPDATE stripe_transfer SET mirakl_created_date = \'2000-01-01\' WHERE mirakl_created_date IS NULL');
        $this->addSql('ALTER TABLE stripe_transfer ALTER COLUMN mirakl_created_date SET NOT NULL');
        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN mirakl_created_date TO mirakl_update_time');
        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN status_reason TO failed_reason');

        $this->addSql('ALTER TABLE stripe_refund DROP COLUMN transaction_id');
        $this->addSql('ALTER TABLE stripe_refund ADD COLUMN stripe_reversal_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_refund ADD COLUMN commission INT');
        $this->addSql('ALTER TABLE stripe_refund RENAME COLUMN status_reason TO failed_reason');

        $this->addSql('UPDATE stripe_payout SET currency = 0 WHERE currency IS NULL');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN currency SET DEFAULT \'eur\'');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN currency SET NOT NULL');
        $this->addSql('UPDATE stripe_payout SET amount = 0 WHERE amount IS NULL');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN amount SET DEFAULT 0');
        $this->addSql('ALTER TABLE stripe_payout ALTER COLUMN amount SET NOT NULL');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN payout_id TO stripe_payout_id');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN mirakl_created_date TO mirakl_update_time');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN status_reason TO failed_reason');

        $this->addSql('DROP TABLE config');
        $this->addSql('DROP SEQUENCE config_id_seq CASCADE');
    }
}
