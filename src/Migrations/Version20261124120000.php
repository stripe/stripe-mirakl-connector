<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track Stripe request attempts for idempotent payment operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_mapping ADD stripe_request_attempt INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stripe_refund ADD stripe_request_attempt INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stripe_transfer ADD stripe_request_attempt INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stripe_payout ADD stripe_request_attempt INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_mapping DROP stripe_request_attempt');
        $this->addSql('ALTER TABLE stripe_refund DROP stripe_request_attempt');
        $this->addSql('ALTER TABLE stripe_transfer DROP stripe_request_attempt');
        $this->addSql('ALTER TABLE stripe_payout DROP stripe_request_attempt');
    }
}
