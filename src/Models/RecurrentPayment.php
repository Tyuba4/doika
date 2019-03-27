<?php

namespace Diglabby\Doika\Models;

use Carbon\CarbonInterval;
use Diglabby\Doika\Services\PaymentGateways\BePaidApiContext;
use GuzzleHttp\Client as HttpClient;
use Money\Money;

class RecurrentPayment
{
    private const GATEWAY_ID = 'bePaid';

    protected const COUNTRY_OWNER = 'BY';

    protected const DEFAULT_VALUE = 'default';

    protected const BASE_PAYMENT_GATEWAY_URI = 'https://api.bepaid.by';

    protected const REDIRECT_URL = 'https://doika.falanster.by';

    protected $firstName;

    protected $lastName;

    protected $email;

    protected $phone;

    protected $idCustomer;

    protected $idPlan;

    protected $idSubscription;

    protected $responseFromPaymentGateway;

    protected $apiContext;

    protected $httpClient;

    public function __construct(BePaidApiContext $apiContext, HttpClient $httpClient, $firstName, $lastName, $email, $phone)
    {
        $this->apiContext = $apiContext;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->email = $email;
        $this->phone = $phone;
        $this->httpClient = $httpClient;
    }

    private function createCustomer()
    {
        $GetCustomerParams = [
            'test' => ! $this->apiContext->live,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => self::COUNTRY_OWNER,
            'ip' => self::DEFAULT_VALUE,
            'city' => self::DEFAULT_VALUE,
            'address' => self::DEFAULT_VALUE,
            'zip' => self::DEFAULT_VALUE
        ];
        $response = $this->httpClient->request('POST', '/customers', [
            'auth' => [$this->apiContext->marketId, $this->apiContext->marketKey],
            'headers' => ['Accept' => 'application/json'],
            'json' => $GetCustomerParams,
            'verify' => false,
        ]);
        $this->responseFromPaymentGateway = $response->getBody();
        $this->idCustomer = $this->responseFromPaymentGateway->id;

        return $this->responseFromPaymentGateway;
    }

    private function generatePlanName(Money $money, Campaign $campaign): string
    {
        return "Campaign: $campaign->name, {$money->getCurrency()->getCode()} {$money->getAmount()}";
    }

    /**
     * @see https://docs.bepaid.by/ru/subscriptions/plans
     */
    private function createPlan(Money $money, Campaign $campaign, CarbonInterval $dateInterval): string
    {
        $planName = $this->generatePlanName($money, $campaign);

        $getTokenParams = [
            'test' => ! $this->apiContext->live,
            'title' => $planName,
            'currency' => $money->getCurrency()->getCode(),
            'plan' => [
                'amount' => (int) $money->getAmount(),
                'interval' => $dateInterval->months,
                'interval_unit' => 'month',
            ],
            'language' => app()->getLocale(),
            'infinite' => true,
        ];
        $response = $this->httpClient->request('POST', '/plans', [
            'auth' => [$this->apiContext->marketId, $this->apiContext->marketKey],
            'headers' => ['Accept' => 'application/json'],
            'json' => $getTokenParams,
            'verify' => false,
        ]);
        $this->responseFromPaymentGateway = $response->getBody();

        return $this->responseFromPaymentGateway->id;
    }

    public function createSubscription(Money $money, Campaign $campaign, Donator $donator, string $paymentInterval): Subscription
    {
        $dateInterval = new CarbonInterval($paymentInterval);
        $planId = $this->createPlan($money, $campaign, $dateInterval);
        $customerId = $this->createCustomer();

        $GetSubscriptionParams = [
            'customer' => [
                'id' => $customerId
            ],
            'plan' => [
                'id' => $planId,
            ],
            'return_url' => self::REDIRECT_URL,
            'settings' => [
                'language' => app()->getLocale(),
            ]
        ];
        $response = $this->httpClient->request('POST', '/subscriptions', [
            'auth' => [$this->apiContext->marketId, $this->apiContext->marketKey],
            'headers' => ['Accept' => 'application/json'],
            'json' => $GetSubscriptionParams,
            'verify' => false,
        ]);

        $this->responseFromPaymentGateway = $response->getBody();
        $gatewaySubscriptionId = $this->responseFromPaymentGateway->id;

        $subscription = new Subscription([
            'donator_id' => $donator->id,
            'campaign_id' => $campaign->id,
            'payment_gateway' => self::GATEWAY_ID,
            'payment_gateway_subscription_id' => $gatewaySubscriptionId,
            'amount' => (int) $money->getAmount(),
            'currency' => $money->getCurrency()->getCode(),
            'payment_interval' => $paymentInterval,
        ]);
        $subscription->save();

        return $subscription;
    }
}
