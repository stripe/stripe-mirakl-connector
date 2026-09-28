<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the Stripe Charge currency on payment mappings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_mapping ADD stripe_currency VARCHAR(3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_mapping DROP stripe_currency');
    }
}
