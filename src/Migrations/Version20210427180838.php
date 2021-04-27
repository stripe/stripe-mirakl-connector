<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210427180838 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Fix transfers for subscription fees and extra invoices.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('UPDATE stripe_transfer SET status = \'TRANSFER_ON_HOLD\' WHERE status = \'TRANSFER_ABORTED\' AND status_reason LIKE \'Amount must be positive, input was: -%\'');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('UPDATE stripe_transfer SET status = \'TRANSFER_ABORTED\' WHERE status = \'TRANSFER_ON_HOLD\' AND status_reason LIKE \'Amount must be positive, input was: -%\'');
    }
}
