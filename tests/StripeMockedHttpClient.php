<?php

namespace App\Tests;

use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;

class StripeMockedHttpClient implements ClientInterface
{
		public const ACCOUNT_PLATFORM = 'acct_platform';
		public const ACCOUNT_BASIC = 'acct_basic';
		public const ACCOUNT_PAYIN_DISABLED = 'account_payin_disabled';
		public const ACCOUNT_PAYOUT_DISABLED = 'account_payout_disabled';
		public const ACCOUNT_OAUTH = 'account_oauth';
		public const ACCOUNT_NEW = 'account_new';

		public const CHARGE_BASIC = 'ch_basic';
		public const CHARGE_PAYMENT = 'py_basic';
		public const CHARGE_STATUS_AUTHORIZED = 'ch_status_authorized';
		public const CHARGE_STATUS_CAPTURED = 'ch_status_captured';
		public const CHARGE_STATUS_FAILED = 'ch_status_failed';
		public const CHARGE_STATUS_PENDING = 'ch_status_pending';
		public const CHARGE_REFUNDED = 'ch_refunded';
		public const CHARGE_NOT_FOUND = 'ch_not_found';
		public const CHARGE_WITH_TRANSFER = 'ch_with_transfer';

		public const PAYMENT_INTENT_BASIC = 'pi_basic';
		public const PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD = 'pi_status_requires_payment_method';
		public const PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION = 'pi_status_requires_confirmation';
		public const PAYMENT_INTENT_STATUS_REQUIRES_ACTION = 'pi_status_requires_action';
		public const PAYMENT_INTENT_STATUS_PROCESSING = 'pi_status_processing';
		public const PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE = 'pi_status_requires_capture';
		public const PAYMENT_INTENT_STATUS_CANCELED = 'pi_status_canceled';
		public const PAYMENT_INTENT_STATUS_SUCCEEDED = 'pi_status_succeeded';
		public const PAYMENT_INTENT_REFUNDED = 'pi_refunded';
		public const PAYMENT_INTENT_NOT_FOUND = 'pi_not_found';

		public const PAYOUT_BASIC = 'po_basic';

		public const REFUND_BASIC = 're_basic';

		public const TRANSFER_BASIC = 'tr_basic';
		public const TRANSFER_WITH_REVERSAL = 'tr_with_reversal';

		public const TRANSFER_REVERSAL_BASIC = 'trr_basic';

    protected $errorMessage;

    public function __construct()
    {
        $this->errorMessage = json_encode([
            'error' => [
                'code' => 'stripe_error',
                'type' => 'invalid_request_error',
            ],
        ]);
    }

    /**
     * @param string $method  The HTTP method being used
     * @param string $absUrl  The URL being requested, including domain and protocol
     * @param array  $headers Headers to be used in the request (full strings, not KV pairs)
     * @param array  $params  KV pairs for parameters. Can be nested for arrays and hashes
     * @param bool   $hasFile Whether or not $params references a file (via an @ prefix or
     *                        CURLFile)
     *
     * @throws \Stripe\Exception\ApiConnectionException
     * @throws \Stripe\Exception\InvalidRequestException
     * @throws \Stripe\Exception\UnexpectedValueException
     *
     * @return array an array whose first element is raw request body, second
     *               element is HTTP status code and third arResponseInterfaceray of HTTP headers
     */
    public function request($method, $url, $headers, $params, $hasFile = false)
    {
				if ('https://connect.stripe.com/oauth/token' === $url) {
						return [ json_encode($this->mockOauth()), 200, [] ];
				}

				$path = str_replace('/v1/', '', parse_url($url, PHP_URL_PATH));
				$composants = explode('/', $path);

				$resource = $composants[0];
				$id = $composants[1] ?? null;
				$action = $composants[2] ?? null;
				$stripeAccount = $this->parseHeaders($headers)['stripe-account'] ?? null;

				$response = null;
        switch ($resource) {
            case 'account':
								$response = $this->mockPlatformAccount();
								break;
            case 'accounts':
								$response = $this->mockAccounts($id);
								break;
            case 'charges':
								$response = $this->mockCharges($id, $action);
								break;
            case 'payment_intents':
								$response = $this->mockPaymentIntents($id, $action);
								break;
            case 'payouts':
								$response = $this->mockPayouts($stripeAccount);
								break;
            case 'refunds':
								$response = $this->mockRefunds($params);
								break;
            case 'transfers':
								if ('reversals' === $action) {
										$response = $this->mockTransferReversals($id);
								} else {
										$response = $this->mockTransfers($params, $stripeAccount);
								}
								break;
            default:
								throw new \Exception('Unpexpected URL: ' . $url);
        }

				return [ json_encode($response), 200, [] ];
    }

    private function getBasicObject($id, $object)
    {
        return [
						'id' => $id,
						'object' => $object
				];
    }

    private function getBasicList($resource)
    {
        return [
						'object' => 'list',
						'url' => "/v1/$resource",
						'has_more' => false,
						'data' => []
				];
    }

    private function mockPlatformAccount()
    {
        return $this->mockAccounts(self::ACCOUNT_PLATFORM);
    }

    private function mockAccounts($id)
    {
				$account = $this->getBasicObject($id, 'account');
				$account['charges_enabled'] = true;
				$account['payouts_enabled'] = true;
				$account['requirements'] = [];
				$account['requirements']['disabled_reason'] = null;
        return $account;
    }

    private function mockOauth()
    {
        return [
            'access_token' => 'token',
            'stripe_user_id' => self::ACCOUNT_OAUTH,
        ];
    }

    private function mockCharges($id, $action)
    {
				$charge = $this->getBasicObject($id, 'charge');
				$charge['refunded'] = false;
				switch ($id) {
					case self::CHARGE_BASIC:
					case self::CHARGE_PAYMENT:
					case self::CHARGE_STATUS_CAPTURED:
							$charge['status'] = 'succeeded';
							$charge['captured'] = true;
							break;
					case self::CHARGE_STATUS_AUTHORIZED:
							$charge['status'] = 'succeeded';
							$charge['captured'] = false;
							break;
					case self::CHARGE_STATUS_FAILED:
							$charge['status'] = 'failed';
							$charge['captured'] = false;
							break;
					case self::CHARGE_STATUS_PENDING:
							$charge['status'] = 'pending';
							$charge['captured'] = false;
							break;
					case self::CHARGE_REFUNDED:
							$charge['status'] = 'succeeded';
							$charge['captured'] = true;
							$charge['refunded'] = true;
							break;
					case self::CHARGE_NOT_FOUND:
					default:
							throw new InvalidRequestException("$id not found", 404);
				}

				if ('capture' === $action) {
						if ('succeeded' !== $charge['status']) {
								throw new ApiConnectionException(
										"Can't capture charge in state {$charge['status']}",
										400
								);
						}
						if (true === $charge['captured']) {
								throw new ApiConnectionException(
										"Can't capture charge already captured",
										400
								);
						}

						$charge['captured'] = true;
				}

        return $charge;
    }

    private function mockPaymentIntents($id, $action)
    {
				$pi = $this->getBasicObject($id, 'payment_intent');
				switch ($id) {
					case self::PAYMENT_INTENT_BASIC:
							$pi['status'] = 'succeeded';
							$pi['charges'] = $this->getBasicList('charges');
							$pi['charges']['data'][] = $this->mockCharges(self::CHARGE_BASIC, null);
							break;
					case self::PAYMENT_INTENT_STATUS_SUCCEEDED:
							$pi['status'] = 'succeeded';
							$pi['charges'] = $this->getBasicList('charges');
							$pi['charges']['data'][] = $this->mockCharges(self::CHARGE_STATUS_CAPTURED, null);
							break;
					case self::PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD:
							$pi['status'] = 'requires_payment_method';
							break;
					case self::PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION:
							$pi['status'] = 'requires_confirmation';
							break;
					case self::PAYMENT_INTENT_STATUS_REQUIRES_ACTION:
							$pi['status'] = 'requires_action';
							break;
					case self::PAYMENT_INTENT_STATUS_PROCESSING:
							$pi['status'] = 'processing';
							break;
					case self::PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE:
							$pi['status'] = 'requires_capture';
							break;
					case self::PAYMENT_INTENT_STATUS_CANCELED:
							$pi['status'] = 'canceled';
							break;
					case self::PAYMENT_INTENT_REFUNDED:
							$pi['status'] = 'succeeded';
							$pi['charges'] = $this->getBasicList('charges');
							$pi['charges']['data'][] = $this->mockCharges(self::CHARGE_REFUNDED, null);
							break;
					case self::PAYMENT_INTENT_NOT_FOUND:
					default:
							throw new InvalidRequestException("$id not found", 404);
				}

				if ('capture' === $action || 'cancel' === $action) {
						if ('requires_capture' !== $pi['status']) {
								throw new ApiConnectionException(
										"Can't $action charge in state {$pi['status']}",
										400
								);
						}

						$pi['status'] = 'capture' === $action ? 'succeeded' : 'canceled';
				}

        return $pi;
    }

    private function mockPayouts($stripeAccount)
    {
				switch ($stripeAccount) {
						case self::ACCOUNT_BASIC:
								return $this->getBasicObject(self::PAYOUT_BASIC, 'payout');
						case self::ACCOUNT_PAYOUT_DISABLED:
								throw new ApiConnectionException("Payouts disabled", 400);
						default:
								throw new ApiConnectionException("$id not found", 404);
				}
    }

    private function mockRefunds($params)
    {
				$id = $params['charge'];
				switch ($id) {
					case self::CHARGE_BASIC:
					case self::CHARGE_STATUS_AUTHORIZED:
							return $this->getBasicObject(self::REFUND_BASIC, 'refund');
					case self::CHARGE_REFUNDED:
							throw new ApiConnectionException("$id already refunded", 400);
					default:
							throw new ApiConnectionException("$id not found", 404);
				}
    }

    private function mockTransfers($params, $stripeAccount)
    {
				$sourceTransaction = $params['source_transaction'] ?? null;
				if (self::CHARGE_WITH_TRANSFER === $sourceTransaction) {
						throw new ApiConnectionException('Transfer with source_transaction and charge has no more funds left.', 400);
				}

				if (self::ACCOUNT_PAYIN_DISABLED === $params['destination'] ||
						self::ACCOUNT_PAYIN_DISABLED === $stripeAccount) {
						throw new ApiConnectionException('Transfers disabled.', 400);
				}

				return $this->getBasicObject(self::TRANSFER_BASIC, 'transfer');
    }

    private function mockTransferReversals($id)
    {
				switch ($id) {
					case self::TRANSFER_WITH_REVERSAL:
							throw new ApiConnectionException('Transfer already reversed.', 400);
					default:
							return $this->getBasicObject(
									self::TRANSFER_REVERSAL_BASIC,
									'transfer_reversal'
							);
				}
    }

    private function parseHeaders($rawHeaders)
    {
        $output = [];
        foreach ($rawHeaders as $v) {
            $h = preg_split('/:\s*/', $v);
            $output[strtolower($h[0])] = $h[1];
        }

        return $output;
    }
}
