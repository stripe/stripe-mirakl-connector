<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Stripe\Stripe;
use App\Service\StripeClient;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Shivas\VersioningBundle\Service\VersionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

final class Version20201207112134 extends AbstractMigration implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface|null
     */
    private $container;

    public function getDescription(): string
    {
        return '';
    }

    /**
     * @param Schema $schema
     * @throws \Exception
     */
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->connectStripe();

        $this->addSql('ALTER TABLE stripe_charge ADD COLUMN stripe_amount INT');
        $this->fetchAndUpdateMissingAmounts();
        $this->addSql('ALTER TABLE stripe_charge ALTER COLUMN stripe_amount SET NOT NULL; ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge DROP COLUMN stripe_amount');
    }

    /**
     * Sets the container.
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    private function fetchAndUpdateMissingAmounts()
    {
        $stripeCharges = $this->connection->fetchAllAssociative('SELECT id, stripe_charge_id FROM stripe_charge');

        $updateQuery = 'UPDATE stripe_charge SET stripe_amount = :amount WHERE id = :id';
        // Fetch matching amount from Stripe and update them in DB
        foreach ($stripeCharges as $stripeCharge) {
            try {
                $stripeAmountObject = \Stripe\Charge::retrieve($stripeCharge['stripe_charge_id']);
                $this->addSql($updateQuery, [
                    'id' => $stripeCharge['id'],
                    'amount' => $stripeAmountObject->amount,
                ]);
            } catch (\Exception $e) {
                $this->write(sprintf(
                    'An error occured updating Charge of ID: %s with its amount. Skipping.',
                    $stripeCharge['id']
                ));
            }
        }
    }

    /**
     *
     * @throws \Exception
     */
    private function connectStripe()
    {
        if (null === $this->container) {
            throw new \Exception('Missing container');
        }

        $stripeClientSecret = $this->container->getParameter('app.stripe.client_secret');
        /** @var VersionManager $versionManager */
        $versionManager = $this->container->get('Shivas\VersioningBundle\Service\VersionManager');

        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(
            StripeClient::APP_NAME,
            $versionManager->getVersion()->__toString(),
            StripeClient::APP_REPO,
            StripeClient::APP_PARTNER_ID
        );
        Stripe::setApiVersion(StripeClient::APP_API_VERSION);
    }
}
