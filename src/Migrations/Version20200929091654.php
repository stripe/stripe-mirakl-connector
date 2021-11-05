<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20200929091654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER SEQUENCE mirakl_refund_id_seq RENAME TO stripe_refund_id_seq;');
        $this->addSql('ALTER TABLE mirakl_refund RENAME TO stripe_refund;');
        $this->addSql('ALTER INDEX uniq_d3aa86f8518a673 RENAME TO UNIQ_F36169648518A673');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER SEQUENCE stripe_refund_id_seq RENAME TO mirakl_refund_id_seq;');
        $this->addSql('ALTER TABLE stripe_refund RENAME TO mirakl_refund;');
        $this->addSql('ALTER INDEX uniq_f36169648518a673 RENAME TO uniq_d3aa86f8518a673');
    }
}
