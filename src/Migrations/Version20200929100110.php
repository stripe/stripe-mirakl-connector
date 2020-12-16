<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20200929100110 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER SEQUENCE mirakl_stripe_mapping_id_seq RENAME TO account_mapping_id_seq;');
        $this->addSql('ALTER TABLE mirakl_stripe_mapping RENAME TO account_mapping;');

        $this->addSql('ALTER INDEX uniq_6c20b92df5fd776e RENAME TO UNIQ_80CD38C2F5FD776E');
        $this->addSql('ALTER INDEX uniq_6c20b92de065f932 RENAME TO UNIQ_80CD38C2E065F932');

        $this->addSql('ALTER TABLE stripe_transfer DROP CONSTRAINT fk_1d737ff358bd25d6');
        $this->addSql('DROP INDEX idx_1d737ff358bd25d6');
        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN mirakl_stripe_mapping_id TO account_mapping_id');
        $this->addSql('ALTER TABLE stripe_transfer ADD CONSTRAINT FK_1D737FF36208568 FOREIGN KEY (account_mapping_id) REFERENCES account_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_1D737FF36208568 ON stripe_transfer (account_mapping_id)');

        $this->addSql('ALTER TABLE stripe_payout DROP CONSTRAINT fk_e663d43e58bd25d6');
        $this->addSql('DROP INDEX idx_e663d43e58bd25d6');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN mirakl_stripe_mapping_id TO account_mapping_id');
        $this->addSql('ALTER TABLE stripe_payout ADD CONSTRAINT FK_E663D43E6208568 FOREIGN KEY (account_mapping_id) REFERENCES account_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_E663D43E6208568 ON stripe_payout (account_mapping_id)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER SEQUENCE account_mapping_id_seq RENAME TO mirakl_stripe_mapping_id_seq;');
        $this->addSql('ALTER TABLE account_mapping RENAME TO mirakl_stripe_mapping;');

        $this->addSql('ALTER INDEX uniq_80cd38c2f5fd776e RENAME TO uniq_6c20b92df5fd776e');
        $this->addSql('ALTER INDEX uniq_80cd38c2e065f932 RENAME TO uniq_6c20b92de065f932');

        $this->addSql('ALTER TABLE stripe_transfer DROP CONSTRAINT FK_1D737FF36208568');
        $this->addSql('ALTER TABLE stripe_payout DROP CONSTRAINT FK_E663D43E6208568');

        $this->addSql('DROP INDEX IDX_1D737FF36208568');
        $this->addSql('ALTER TABLE stripe_transfer RENAME COLUMN account_mapping_id TO mirakl_stripe_mapping_id');
        $this->addSql('ALTER TABLE stripe_transfer ADD CONSTRAINT fk_1d737ff358bd25d6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_1d737ff358bd25d6 ON stripe_transfer (mirakl_stripe_mapping_id)');
        $this->addSql('DROP INDEX IDX_E663D43E6208568');
        $this->addSql('ALTER TABLE stripe_payout RENAME COLUMN account_mapping_id TO mirakl_stripe_mapping_id');
        $this->addSql('ALTER TABLE stripe_payout ADD CONSTRAINT fk_e663d43e58bd25d6 FOREIGN KEY (mirakl_stripe_mapping_id) REFERENCES mirakl_stripe_mapping (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_e663d43e58bd25d6 ON stripe_payout (mirakl_stripe_mapping_id)');
    }
}
