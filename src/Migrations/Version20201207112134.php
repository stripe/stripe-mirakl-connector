<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\StripeClient;
use App\Service\VersionService;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Stripe\Stripe;

final class Version20201207112134 extends AbstractMigration
{

    public function getDescription(): string
    {
        return '';
    }

    /**
     * @throws \Exception
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->connectStripe();

        $this->addSql('ALTER TABLE stripe_charge ADD COLUMN stripe_amount INT');
        $this->fetchAndUpdateMissingAmounts();
        $this->addSql('ALTER TABLE stripe_charge ALTER COLUMN stripe_amount SET NOT NULL; ');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform, 'Migration can only be executed safely on \'postgresql\'.');

        $this->addSql('ALTER TABLE stripe_charge DROP COLUMN stripe_amount');
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
            StripeClient::APP_NAME,
            $version,
            StripeClient::APP_REPO,
            StripeClient::APP_PARTNER_ID
        );
        Stripe::setApiVersion(StripeClient::APP_API_VERSION);
    }
}
