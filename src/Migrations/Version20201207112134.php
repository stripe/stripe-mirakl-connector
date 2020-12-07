<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Repository\StripeChargeRepository;
use App\Utils\StripeProxy;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201207112134 extends AbstractMigration
{
    private $stripeChargeRepository;
    private $stripeProxy;

    public function setServices(StripeChargeRepository $stripeChargeRepository, StripeProxy $stripeProxy)
    {
        $this->stripeChargeRepository = $stripeChargeRepository;
        $this->stripeProxy = $stripeProxy;
    }

    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge ADD stripe_amount INT');
    }

    public function postUp(Schema $schema): void
    {
        $stripeChargesWithoutAmount = $this->stripeChargeRepository->findBy(['stripe_amount' => null]);
        foreach ($stripeChargesWithoutAmount as $stripeCharge) {
            $fetchedCharge = $this->stripeProxy->fetchStripeCharge($stripeCharge->getStripeChargeId());
            $stripeCharge->setStripeAmount($fetchedCharge['amount']);
        }
        $this->stripeChargeRepository->flush();
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge DROP stripe_amount');
    }
}
