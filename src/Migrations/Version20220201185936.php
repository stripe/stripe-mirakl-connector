<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220201185936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete AccountOnboarding.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('DROP SEQUENCE account_onboarding_id_seq CASCADE');
        $this->addSql('DROP TABLE account_onboarding');
        $this->addSql('ALTER TABLE account_mapping ADD onboarding_token VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf('postgresql' !== $this->connection->getDatabasePlatform()->getName(), 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE account_mapping DROP COLUMN onboarding_token');
        $this->addSql('CREATE SEQUENCE account_onboarding_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE account_onboarding (id INT NOT NULL, mirakl_shop_id INT NOT NULL, stripe_state VARCHAR(48) NOT NULL, creation_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP NOT NULL, PRIMARY KEY(id))');
    }
}
