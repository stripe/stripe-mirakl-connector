<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20191001091302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE mirakl_invoice_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE mirakl_invoice (id INT NOT NULL, mirakl_stripe_mapping_id INT DEFAULT NULL, amount INT NOT NULL, mirakl_invoice_id INT NOT NULL, stripe_payout_id VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_288EA4F758BD25D6 ON mirakl_invoice (mirakl_stripe_mapping_id)');
        $this->addSql('ALTER TABLE mirakl_invoice ADD CONSTRAINT FK_288EA4F758BD25D6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
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
