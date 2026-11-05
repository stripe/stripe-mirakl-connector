<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add onboarding token expiry; legacy tokens without expiry are rejected.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_mapping ADD onboarding_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_mapping DROP onboarding_token_expires_at');
    }
}
