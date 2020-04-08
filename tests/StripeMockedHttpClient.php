<?php

namespace App\Tests;

use Stripe\HttpClient\ClientInterface;

class StripeMockedHttpClient implements ClientInterface
{
    public function __construct()
    {
        $this->errorMessage = json_encode([
            'error' => [
                'code' => 'stripe_error',
                'type' => 'invalid_request_error',
            ],
        ]);
    }

    private function responseFactory($method, $url, $params, $headers)
    {
        switch ($url) {
            case 'https://api.stripe.com/v1/accounts/acct_1':
                return [$this->getJsonStripeAccount('acct_1'), 200, []];
            case 'https://api.stripe.com/v1/accounts/acct_12345':
                return [$this->getJsonStripeAccount('acct_12345'), 200, []];
            case 'https://connect.stripe.com/oauth/token':
                return [$this->getJsonAccessToken('acct_12345'), 200, []];
            case 'https://api.stripe.com/v1/transfers':
                switch ($params['destination']) {
                    case 'acct_1':
                        return [$this->getJsonStripeTransfer('transfer_1'), 200, []];
                    default:
                        return [$this->errorMessage, 404, []];
                }
                // no break
            case 'https://api.stripe.com/v1/payouts':
                switch ($this->parseHeaders($headers)['stripe-account']) {
                    case 'acct_1':
                        return [$this->getJsonStripePayout('payout_2'), 200, []];
                    default:
                        return [$this->errorMessage, 404, []];
                }
                // no break
            case 'https://api.stripe.com/v1/charges/ch_transaction_4':
                return [$this->getJsonEmptyRefunds('ch_transaction_4'), 200, []];
            case 'https://api.stripe.com/v1/transfers/transfer_4':
                return [$this->getJsonEmptyReversals('transfer_4'), 200, []];
            case 'https://api.stripe.com/v1/charges/ch_transaction_5':
                return [$this->getJsonRefunds('ch_transaction_5', '1105'), 200, []];
            case 'https://api.stripe.com/v1/transfers/transfer_5':
                return [$this->getJsonReversals('transfer_5', '1106'), 200, []];
            case 'https://api.stripe.com/v1/refunds':
                switch ($params['charge']) {
                    case 'ch_transaction_4':
                        return [$this->getJsonStripeRefund('refund_4'), 200, []];
                    default:
                        return [$this->errorMessage, 404, []];
                }
                // no break
            case 'https://api.stripe.com/v1/transfers/transfer_4/reversals':
                return [$this->getJsonStripeReversal('trr_4'), 200, []];
            default:
                return [$this->errorMessage, 403, []];
        }
    }

    private function getJsonStripeAccount($id)
    {
        return json_encode([
            'id' => $id,
            'object' => 'account',
            'charges_enabled' => true,
            'payouts_enabled' => false,
            'requirements' => [
                'disabled_reason' => 'requirements.past.due',
            ],
        ]);
    }

    private function getJsonAccessToken($id)
    {
        return json_encode([
            'access_token' => 'token',
            'stripe_user_id' => $id,
        ]);
    }

    private function getJsonStripeTransfer($transferId)
    {
        return json_encode([
            'id' => $transferId,
        ]);
    }

    private function getJsonStripePayout($payoutId)
    {
        return json_encode([
            'id' => $payoutId,
        ]);
    }

    private function getJsonRefunds($chargeId, $miraklRefundId)
    {
        return json_encode([
            'id' => $chargeId,
            'object' => 'charge',
            'refunds' => [
              'object' => 'list',
              'data' => [
                [
                    'id' => 'refund_x',
                    'metadata' => ['miraklRefundId' => $miraklRefundId],
                ],
              ],
              'has_more' => false,
            ],
          ]);
    }

    private function getJsonEmptyRefunds($chargeId)
    {
        return json_encode([
            'id' => $chargeId,
            'object' => 'charge',
            'refunds' => [
              'object' => 'list',
              'data' => [],
              'has_more' => false,
            ],
          ]);
    }

    private function getJsonReversals($transferId, $miraklRefundId)
    {
        return json_encode([
            'id' => $transferId,
            'object' => 'transfer',
            'reversals' => [
              'object' => 'list',
              'data' => [
                [
                    'id' => 'trr_x',
                    'metadata' => ['miraklRefundId' => $miraklRefundId],
                ],
              ],
              'has_more' => false,
            ],
          ]);
    }

    private function getJsonEmptyReversals($transferId)
    {
        return json_encode([
            'id' => $transferId,
            'object' => 'transfer',
            'reversals' => [
              'object' => 'list',
              'data' => [],
              'has_more' => false,
            ],
          ]);
    }

    private function getJsonStripeRefund($refundId)
    {
        return json_encode([
            'id' => $refundId,
        ]);
    }

    private function getJsonStripeReversal($reversalId)
    {
        return json_encode([
            'id' => $reversalId,
        ]);
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

    /**
     * @param string $method  The HTTP method being used
     * @param string $absUrl  The URL being requested, including domain and protocol
     * @param array  $headers Headers to be used in the request (full strings, not KV pairs)
     * @param array  $params  KV pairs for parameters. Can be nested for arrays and hashes
     * @param bool   $hasFile Whether or not $params references a file (via an @ prefix or
     *                        CURLFile)
     *
     * @throws \Stripe\Exception\ApiConnectionException
     * @throws \Stripe\Exception\UnexpectedValueException
     *
     * @return array an array whose first element is raw request body, second
     *               element is HTTP status code and third arResponseInterfaceray of HTTP headers
     */
    public function request($method, $url, $headers, $params, $hasFile = false)
    {
        return $this->responseFactory($method, $url, $params, $headers);
    }
}
