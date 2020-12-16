<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Service\ConfigService;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201216133324 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Adding config table.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SEQUENCE config_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE config (id INT NOT NULL, key VARCHAR(255) NOT NULL, value VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_84CED2B33EFA2266 ON config (config_id_seq)');

        $this->addSql('INSERT INTO config (key, value) VALUES (' . ConfigService::PROCESS_TRANSFER_CHECKPOINT . ',
          (SELECT mirakl_update_time FROM stripe_transfer ORDER BY mirakl_update_time DESC LIMIT 1)
        )');

        $this->addSql('ALTER TABLE stripe_transfer DROP mirakl_update_time');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE config_id_seq CASCADE');
        $this->addSql('DROP TABLE config');
        $this->addSql('ALTER TABLE stripe_transfer ADD mirakl_update_time TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
    }
}
