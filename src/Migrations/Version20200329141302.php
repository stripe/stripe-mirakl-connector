<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200329141302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE mirakl_refund_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE mirakl_refund (id INT NOT NULL, amount INT NOT NULL, currency VARCHAR(255) NOT NULL, mirakl_refund_id VARCHAR(255) NOT NULL, mirakl_order_id VARCHAR(255) NOT NULL, stripe_refund_id VARCHAR(255) DEFAULT NULL, stripe_reversal_id VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, failed_reason VARCHAR(1024) NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SCHEMA IF NOT EXISTS public');
        $this->addSql('DROP SEQUENCE mirakl_invoice_id_seq CASCADE');
        $this->addSql('DROP TABLE mirakl_invoice');
    }
}
