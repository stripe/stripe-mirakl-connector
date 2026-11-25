<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the Mirakl refund amount separately from the tax-inclusive Stripe refund amount.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stripe_refund ADD mirakl_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_refund ADD tax_top_up_stripe_refund_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE stripe_refund ADD tax_top_up_amount INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stripe_refund DROP mirakl_amount');
        $this->addSql('ALTER TABLE stripe_refund DROP tax_top_up_stripe_refund_id');
        $this->addSql('ALTER TABLE stripe_refund DROP tax_top_up_amount');
    }
}
