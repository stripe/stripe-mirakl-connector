<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\VersionService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Stripe\Stripe;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class Version20201127154506 extends AbstractMigration
{
    private const APP_NAME = 'MiraklConnector';
    private const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    private const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    private const APP_API_VERSION = '2019-08-14';


    public function getDescription(): string
    {
        return '';
    }

    /**
     * @throws \Exception
     */
    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->connectStripe();
        $this->changePaymentIntentIdForChargeId();

        $this->addSql('ALTER TABLE stripe_payment RENAME COLUMN stripe_payment_id TO stripe_charge_id');
        $this->addSql('ALTER TABLE stripe_payment RENAME TO stripe_charge');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge RENAME TO stripe_payment');
        $this->addSql('ALTER TABLE stripe_payment RENAME COLUMN stripe_charge_id TO stripe_payment_id ');
    }


    private function changePaymentIntentIdForChargeId()
    {
        // Retrieve stripe_payment records linked to a PI
        $stripePayments = $this->connection->fetchAllAssociative('SELECT id, stripe_payment_id FROM stripe_payment WHERE stripe_payment_id LIKE \'pi_%\' AND status IN (\'to_capture\', \'captured\')');

        $updateQuery = 'UPDATE stripe_payment SET stripe_payment_id = :stripePaymentId WHERE id = :id';

        // Fetch corresponding charge Id from Strapi and update them in DB
        foreach ($stripePayments as $stripePayment) {
            try {
                $stripePaymentIntentObject = \Stripe\PaymentIntent::retrieve($stripePayment['stripe_payment_id']);
                $chargeId = $stripePaymentIntentObject->charges->data[0]['id'];  // ['charges']['data'][0];

                $this->connection->executeQuery($updateQuery, [
                    'id' => $stripePayment['id'],
                    'stripePaymentId' => $chargeId,
                ]);
            } catch (\Exception $e) {
                $this->write(sprintf(
                    'An error occured replacing PaymentIntent of ID: %s by its charge. Skipping.',
                    $stripePayment['stripe_payment_id']
                ));
            }
        }

        // Retrieve stripe_transfer records linked to a PI
        $stripeTransfers = $this->connection->fetchAllAssociative('SELECT id, transaction_id FROM stripe_transfer WHERE transaction_id LIKE \'pi_%\'');

        $updateQuery = 'UPDATE stripe_transfer SET transaction_id = :stripePaymentId WHERE id = :id';

        // Fetch corresponding charge Id from Strapi and update them in DB
        foreach ($stripeTransfers as $stripeTransfer) {
            try {
                $stripePaymentIntentObject = \Stripe\PaymentIntent::retrieve($stripeTransfer['transaction_id']);
                $chargeId = $stripePaymentIntentObject->charges->data[0]['id'];  // ['charges']['data'][0];

                $this->connection->executeQuery($updateQuery, [
                    'id' => $stripeTransfer['id'],
                    'stripePaymentId' => $chargeId,
                ]);
            } catch (\Exception $e) {
                $this->write(sprintf(
                    'An error occured replacing PaymentIntent of ID: %s by its charge. Skipping.',
                    $stripeTransfer['transaction_id']
                ));
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function connectStripe()
    {
        $stripeClientSecret = getenv('STRIPE_CLIENT_SECRET') ?: ($_SERVER['STRIPE_CLIENT_SECRET'] ?? $_ENV['STRIPE_CLIENT_SECRET'] ?? null);
        if (!$stripeClientSecret) {
            throw new \Exception('Stripe client secret is not set in environment variables.');
        }

        $version = VersionService::getVersion();

        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(
            self::APP_NAME,
            $version,
            self::APP_REPO,
            self::APP_PARTNER_ID
        );
        Stripe::setApiVersion(self::APP_API_VERSION);
    }
}
