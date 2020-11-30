<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Shivas\VersioningBundle\Service\VersionManager;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20201127154506 extends AbstractMigration implements ContainerAwareInterface
{
    private const APP_NAME = 'MiraklConnector';
    private const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    private const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    private const APP_API_VERSION = '2019-08-14';

    /**
     * @var ContainerInterface|null
     */
    private $container;

    public function getDescription() : string
    {
        return '';
    }

    /**
     * @param Schema $schema
     * @throws \Exception
     */
    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->connectStripe();
        $this->changePaymentIntentIdForChargeId();

        $this->addSql('ALTER TABLE stripe_payment RENAME COLUMN stripe_payment_id TO stripe_charge_id');
        $this->addSql('ALTER TABLE stripe_payment RENAME TO stripe_charge');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge RENAME TO stripe_payment');
        $this->addSql('ALTER TABLE stripe_payment RENAME COLUMN stripe_charge_id TO stripe_payment_id ');
    }

    /**
     * Sets the container.
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    private function changePaymentIntentIdForChargeId()
    {
        // Retrieve stripe_payment records linked to a PI
        $paymentIntentEntities = $this->connection->fetchAll('SELECT id, stripe_payment_id FROM stripe_payment WHERE stripe_payment_id LIKE \'pi_%\' AND status IN (\'to_capture\', \'captured\')');

        $updateQuery = 'UPDATE stripe_payment SET stripe_payment_id = :stripePaymentId WHERE id = :id';

        // Fetch corresponding charge Id from Strapi and update them in DB
        foreach ($paymentIntentEntities as $paymentIntentEntity) {
            try {
                $stripePaymentIntentObject = \Stripe\PaymentIntent::retrieve($paymentIntentEntity['stripe_payment_id']);
                $chargeId = $stripePaymentIntentObject->charges->data[0]['id'];  // ['charges']['data'][0];

                $this->connection->executeQuery($updateQuery, [
                    'id' => $paymentIntentEntity['id'],
                    'stripePaymentId' => $chargeId,
                ]);
            } catch (\Exception $e) {
                $this->write(sprintf(
                    'An error occured replacing PaymentIntent of ID: %s by its charge. Skipping.',
                    $paymentIntentEntity['stripe_payment_id']
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
            self::APP_NAME,
            $versionManager->getVersion()->__toString(),
            self::APP_REPO,
            self::APP_PARTNER_ID
        );
        Stripe::setApiVersion(self::APP_API_VERSION);
    }
}
