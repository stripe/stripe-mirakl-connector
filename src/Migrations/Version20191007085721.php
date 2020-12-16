<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20191007085721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('DROP SEQUENCE mirakl_order_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE mirakl_invoice_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE stripe_transfer_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE stripe_payout_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE stripe_transfer (id INT NOT NULL, mirakl_stripe_mapping_id INT DEFAULT NULL, mirakl_id VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, transfer_id VARCHAR(255) DEFAULT NULL, amount INT NOT NULL, status VARCHAR(255) NOT NULL, failed_reason VARCHAR(1024) DEFAULT NULL, currency VARCHAR(255) NOT NULL, mirakl_update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_1D737FF358BD25D6 ON stripe_transfer (mirakl_stripe_mapping_id)');
        $this->addSql('CREATE UNIQUE INDEX transfer ON stripe_transfer (type, mirakl_id)');
        $this->addSql('CREATE TABLE stripe_payout (id INT NOT NULL, mirakl_stripe_mapping_id INT DEFAULT NULL, amount INT NOT NULL, currency VARCHAR(255) NOT NULL, mirakl_update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, mirakl_invoice_id INT NOT NULL, stripe_payout_id VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_E663D43E58BD25D6 ON stripe_payout (mirakl_stripe_mapping_id)');
        $this->addSql('ALTER TABLE stripe_transfer ADD CONSTRAINT FK_1D737FF358BD25D6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stripe_payout ADD CONSTRAINT FK_E663D43E58BD25D6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE mirakl_order');
        $this->addSql('DROP TABLE mirakl_invoice');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SCHEMA IF NOT EXISTS public');
        $this->addSql('DROP SEQUENCE stripe_transfer_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE stripe_payout_id_seq CASCADE');
        $this->addSql('CREATE SEQUENCE mirakl_order_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE mirakl_invoice_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE mirakl_order (id INT NOT NULL, order_id VARCHAR(255) NOT NULL, shop_id VARCHAR(255) NOT NULL, transfer_id VARCHAR(255) DEFAULT NULL, amount INT NOT NULL, status VARCHAR(255) NOT NULL, currency VARCHAR(255) NOT NULL, mirakl_update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, failed_reason VARCHAR(1024) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_505187ad8d9f6d38 ON mirakl_order (order_id)');
        $this->addSql('CREATE TABLE mirakl_invoice (id INT NOT NULL, mirakl_stripe_mapping_id INT DEFAULT NULL, amount INT NOT NULL, mirakl_invoice_id INT NOT NULL, stripe_payout_id VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, currency VARCHAR(255) NOT NULL, mirakl_update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_288ea4f758bd25d6 ON mirakl_invoice (mirakl_stripe_mapping_id)');
        $this->addSql('ALTER TABLE mirakl_invoice ADD CONSTRAINT fk_288ea4f758bd25d6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE stripe_transfer');
        $this->addSql('DROP TABLE stripe_payout');
    }
}
