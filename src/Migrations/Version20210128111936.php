<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210128111936 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename OnboardingAccount to AccountOnboarding.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE onboarding_account RENAME TO account_onboarding;');
        $this->addSql('ALTER SEQUENCE onboarding_account_id_seq RENAME TO account_onboarding_id_seq;');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE account_onboarding RENAME TO onboarding_account;');
        $this->addSql('ALTER SEQUENCE account_onboarding_id_seq RENAME TO onboarding_account_id_seq;');
    }
}
