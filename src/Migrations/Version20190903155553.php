<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20190903155553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE mirakl_stripe_mapping_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE onboarding_account_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE mirakl_stripe_mapping (id INT NOT NULL, mirakl_shop_id INT NOT NULL, stripe_account_id VARCHAR(255) NOT NULL, payout_enabled BOOLEAN DEFAULT \'false\' NOT NULL, payin_enabled BOOLEAN DEFAULT \'false\' NOT NULL, disabled_reason VARCHAR(255) DEFAULT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, modification_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE onboarding_account (id INT NOT NULL, mirakl_shop_id INT NOT NULL, stripe_state VARCHAR(48) NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SCHEMA IF NOT EXISTS public');
        $this->addSql('DROP SEQUENCE mirakl_stripe_mapping_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE onboarding_account_id_seq CASCADE');
        $this->addSql('DROP TABLE mirakl_stripe_mapping');
        $this->addSql('DROP TABLE onboarding_account');
    }
}
