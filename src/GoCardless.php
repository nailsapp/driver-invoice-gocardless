<?php

/**
 * GoCardless payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-gocardless
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver\Payment;

use Exception;
use GoCardlessPro;
use GoCardlessPro\Client;
use GoCardlessPro\Core\Exception\ApiConnectionException;
use GoCardlessPro\Core\Exception\ApiException;
use GoCardlessPro\Core\Exception\MalformedResponseException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Session;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Exception\ResponseException;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Model\Source;
use Nails\Invoice\Resource;
use stdClass;

/**
 * Class GoCardless
 *
 * @package Nails\Invoice\Driver\Payment
 */
class GoCardless extends PaymentBase
{
    /**
     * The name of the session variable to track the user
     *
     * @var string
     */
    const SESSION_TOKEN_KEY = 'gocardless_session_token';

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param Resource\Invoice $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable(Resource\Invoice $oInvoice): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the currencies which this driver supports, it will only be presented
     * when attempting to pay an invoice in a supported currency
     *
     * @return string[]|null
     */
    public function getSupportedCurrencies(): ?array
    {
        //  Correct as of 2020-08-11
        //  https://gocardless.com/faq/merchants/international-payments/#text-which-currencies-does-gocardless-support?
        return ['AUD', 'CAD', 'DKK', 'EUR', 'GBP', 'NZD', 'SEK', 'USD'];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, 'CARD' for basic credit
     * card details.
     *
     * @return mixed
     */
    public function getPaymentFields()
    {
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns any assets to load during checkout
     *
     * @return array
     */
    public function getCheckoutAssets(): array
    {
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param int                           $iAmount          The payment amount
     * @param Currency                      $oCurrency        The payment currency
     * @param stdClass                      $oData            An array of driver data
     * @param Resource\Invoice\Data\Payment $oPaymentData     The payment data object
     * @param string                        $sDescription     The charge description
     * @param Resource\Payment              $oPayment         The payment object
     * @param Resource\Invoice              $oInvoice         The invoice object
     * @param string                        $sSuccessUrl      The URL to go to after successful payment
     * @param string                        $sErrorUrl        The URL to go to after failed payment
     * @param bool                          $bCustomerPresent Whether the customer is present during the transaction
     * @param Resource\Source|null          $oSource          The saved payment source to use
     *
     * @return ChargeResponse
     * @throws FactoryException
     * @throws ResponseException
     */
    public function charge(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl,
        string $sErrorUrl,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): ChargeResponse {

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Constants::MODULE_SLUG);

        try {

            $oClient = $this->getClient();

            if (empty($oSource)) {
                /**
                 * No payment source selected, create a new redirect flow in order to create a new mandate.
                 */
                return $this->createNewRedirectFlow(
                    $oClient,
                    $oChargeResponse,
                    $oInvoice,
                    $sSuccessUrl
                );
            }

            $sMandateId = getFromArray('mandate_id', (array) $oSource->data);

            if (empty($sMandateId)) {
                throw new DriverException('Could not ascertain the "mandate_id" from the Source object.');
            }

            //  Create a payment against the mandate
            $sTransactionId = $this->createPayment(
                $oClient,
                $sMandateId,
                $sDescription,
                $iAmount,
                $oCurrency,
                $oInvoice,
                $oPaymentData
            );

            if (!empty($sTransactionId)) {

                //  Set the response as processing, GoCardless will let us know when the payment is complete
                $oChargeResponse
                    ->setStatusProcessing()
                    ->setTransactionId($sTransactionId)
                    ->setFee($this->calculateFee($iAmount));

            } else {

                $oChargeResponse
                    ->setStatusFailed(
                        'No transaction ID was returned.',
                        null,
                        'The gateway rejected the request, you may wish to try again.'
                    );
            }

        } catch (ApiConnectionException $e) {

            //  Network error
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (ApiException $e) {

            //  API request failed / record couldn't be created.
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (MalformedResponseException $e) {

            //  Unexpected non-JSON response
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway returned a malformed response, you may wish to try again.'
            );

        } catch (Exception $e) {
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new redirect flow for creating a new mandate
     *
     * @param Client           $oClient         The GoCardless Client
     * @param ChargeResponse   $oChargeResponse The ChargeResponse object
     * @param Resource\Invoice $oInvoice        The invoice being charged
     * @param string           $sSuccessUrl     The success URL
     *
     * @return ChargeResponse
     * @throws ResponseException
     */
    protected function createNewRedirectFlow(
        Client $oClient,
        ChargeResponse $oChargeResponse,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl
    ): ChargeResponse {

        try {

            /**
             * Generate a random session token
             * GoCardless uses this to verify that the person completing the redirect flow
             * is the same person who initiated it.
             */

            Factory::helper('string');

            /** @var Session $oSession */
            $oSession = Factory::service('Session');
            /** @var HttpCodes $oHttpCodes */
            $oHttpCodes = Factory::service('HttpCodes');

            $sSessionToken = random_string('alnum', 32);
            $oSession->setUserData(self::SESSION_TOKEN_KEY, $sSessionToken);

            $aRequestData = [
                'params' => [
                    'session_token'        => $sSessionToken,
                    'success_redirect_url' => $sSuccessUrl,
                ],
            ];

            $oCustomer = $oInvoice->customer();

            if (!empty($oCustomer)) {

                $aAddresses = $oCustomer->addresses();
                if (count($aAddresses)) {
                    $oAddress = reset($aAddresses);
                }

                $aRequestData['params']['prefilled_customer'] = [
                    'address_line1' => (string) ($oAddress->line_1 ?? ''),
                    'address_line2' => (string) ($oAddress->line_2 ?? ''),
                    'city'          => (string) ($oAddress->town ?? ''),
                    'postal_code'   => (string) ($oAddress->postcode ?? ''),
                    'company_name'  => (string) $oCustomer->organisation,
                    'email'         => (string) ($oCustomer->billing_email ?: $oCustomer->email),
                    'family_name'   => (string) $oCustomer->last_name,
                    'given_name'    => (string) $oCustomer->first_name,
                ];
            }

            $oGCResponse = $oClient
                ->redirectFlows()
                ->create($aRequestData);

            if ($oGCResponse->api_response->status_code !== $oHttpCodes::STATUS_CREATED) {
                $oChargeResponse
                    ->setStatusFailed(
                        'Did not receive a 201 CREATED response when creating a redirect flow.',
                        null,
                        'An error occurred whilst communicating with the gateway, you may wish to try again.'
                    );
            }

            $oChargeResponse
                ->setRedirectUrl(
                    $oGCResponse->api_response->body->redirect_flows->redirect_url
                );

        } catch (Exception $e) {
            $oChargeResponse
                ->setStatusFailed(
                    $e->getMessage(),
                    $e->getCode(),
                    'The gateway rejected the request, you may wish to try again.'
                );
        }

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse               $oScaResponse The SCA Response object
     * @param Resource\Payment\Data\Sca $oData        Any saved SCA data
     * @param string                    $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     * @throws NailsException
     */
    public function sca(ScaResponse $oScaResponse, Resource\Payment\Data\Sca $oData, string $sSuccessUrl): ScaResponse
    {
        //  @todo (Pablo - 2019-07-24) - Implement this method
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param Resource\Payment $oPayment  The Payment object
     * @param Resource\Invoice $oInvoice  The Invoice object
     * @param array            $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array            $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     * @throws FactoryException
     * @throws ResponseException
     */
    public function complete(
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        $aGetVars,
        $aPostVars
    ): CompleteResponse {

        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Constants::MODULE_SLUG);
        /** @var Session $oSession */
        $oSession = Factory::service('Session');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        try {

            $oClient = $this->getClient();

            //  Retrieve data required for the completion
            $sRedirectFlowId = getFromArray('redirect_flow_id', $aGetVars);
            $sSessionToken   = $oSession->getUserData(self::SESSION_TOKEN_KEY);

            $oSession->unsetUserData(self::SESSION_TOKEN_KEY);

            if (empty($sRedirectFlowId)) {

                $oCompleteResponse->setStatusFailed(
                    'The complete request was missing $_GET[\'redirect_flow_id\']',
                    0,
                    'The request failed to complete, data was missing.'
                );

            } elseif (empty($sSessionToken)) {

                $oCompleteResponse->setStatusFailed(
                    'The complete request was missing the session token',
                    0,
                    'The request failed to complete, data was missing.'
                );

            } else {

                //  Complete the redirect flow
                $oGCResponse = $oClient
                    ->redirectFlows()
                    ->complete(
                        $sRedirectFlowId,
                        [
                            'params' => [
                                'session_token' => $sSessionToken,
                            ],
                        ]
                    );

                if ($oGCResponse->api_response->status_code === $oHttpCodes::STATUS_OK) {

                    /**
                     * Create a new payment source
                     *
                     * In practice a direct debit is created to be charged against multiple times,
                     * therefore I think it's sane to save this as a payment source for the customer
                     * by default.
                     */

                    $sMandateId = $oGCResponse->api_response->body->redirect_flows->links->mandate;
                    /** @var Source $oSourceModel */
                    $oSourceModel = Factory::model('Source', Constants::MODULE_SLUG);

                    $oSourceModel->create([
                        'customer_id' => $oInvoice->customer->id,
                        'driver'      => $this->getSlug(),
                        'mandate_id'  => $sMandateId,
                    ]);

                    //  Create a payment against the mandate
                    $sTransactionId = $this->createPayment(
                        $oClient,
                        $sMandateId,
                        $oPayment->description,
                        $oPayment->amount->raw,
                        $oPayment->currency,
                        $oInvoice,
                        $oPayment->custom_data
                    );

                    if (!empty($sTransactionId)) {

                        //  Set the response as processing, GoCardless will let us know when the payment is complete
                        $oCompleteResponse
                            ->setStatusProcessing()
                            ->setTransactionId($sTransactionId)
                            ->setFee($this->calculateFee($oPayment->amount->raw));

                    } else {
                        $oCompleteResponse
                            ->setStatusFailed(
                                'No transaction ID was returned.',
                                null,
                                'The gateway rejected the request, you may wish to try again.'
                            );
                    }

                } else {

                    //  @todo: handle errors returned by the GoCardless Client/API
                    $oCompleteResponse->setStatusFailed(
                        null,
                        null,
                        'The gateway rejected the request, you may wish to try again.'
                    );
                }
            }

        } catch (ApiConnectionException $e) {

            //  Network error
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (ApiException $e) {

            //  API request failed / record couldn't be created.
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (MalformedResponseException $e) {

            //  Unexpected non-JSON response
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway returned a malformed response, you may wish to try again.'
            );

        } catch (Exception $e) {
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a payment against a mandate
     *
     * @param Client                        $oClient      The GoCardless client
     * @param string                        $sMandateId   The mandate ID
     * @param string                        $sDescription The payment\'s description
     * @param int                           $iAmount      The amount of the payment
     * @param Currency                      $oCurrency    The currency in which to take payment
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment's payment data object
     *
     * @return string
     * @throws FactoryException
     * @throws GoCardlessPro\Core\Exception\InvalidStateException
     */
    protected function createPayment(
        Client $oClient,
        string $sMandateId,
        string $sDescription,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice $oInvoice,
        Resource\Invoice\Data\Payment $oPaymentData
    ): string {

        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes  = Factory::service('HttpCodes');
        $oGCResponse = $oClient
            ->payments()
            ->create([
                'params' => [
                    'description' => $sDescription,
                    'amount'      => $iAmount,
                    'currency'    => $oCurrency->code,
                    'metadata'    => $this->extractMetaData($oInvoice, $oPaymentData),
                    'links'       => [
                        'mandate' => $sMandateId,
                    ],
                ],
            ]);

        $sTransactionId = null;

        if ($oGCResponse->api_response->status_code === $oHttpCodes::STATUS_CREATED) {
            $sTransactionId = $oGCResponse->api_response->body->payments->id;
        }

        return $sTransactionId;
    }

    // --------------------------------------------------------------------------

    /**
     * Get the GoCardless Client
     *
     * @return Client
     * @throws DriverException
     */
    protected function getClient(): Client
    {
        if (Environment::is(Environment::ENV_PROD)) {

            $sAccessToken = $this->getSetting('sAccessTokenLive');
            $sEnvironment = GoCardlessPro\Environment::LIVE;

        } else {

            $sAccessToken = $this->getSetting('sAccessTokenSandbox');
            $sEnvironment = GoCardlessPro\Environment::SANDBOX;
        }

        if (empty($sAccessToken)) {
            throw new DriverException('Missing GoCardless Access Token.', 1);
        }

        return new Client([
            'access_token' => $sAccessToken,
            'environment'  => $sEnvironment,
        ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Extract the meta data from the invoice and payment data objects
     *
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     *
     * @return array
     */
    protected function extractMetaData(
        Resource\Invoice $oInvoice,
        Resource\Invoice\Data\Payment $oPaymentData
    ): array {

        //  Store any custom meta data; GC allows up to 3 key value pairs with key
        //  names up to 50 characters and values up to 500 characters.

        //  In practice only one custom key can be defined
        $aMetaData = [
            'invoiceId'  => $oInvoice->id,
            'invoiceRef' => $oInvoice->ref,
        ];

        if (!empty($oPaymentData->metadata)) {
            $aMetaData = array_merge($aMetaData, (array) $oPaymentData->metadata);
        }

        $aCleanMetaData = [];
        $iCounter       = 0;

        foreach ($aMetaData as $sKey => $mValue) {

            if ($iCounter === 3) {
                break;
            }

            $aCleanMetaData[substr($sKey, 0, 50)] = substr((string) $mValue, 0, 500);
            $iCounter++;
        }

        return $aCleanMetaData;
    }

    // --------------------------------------------------------------------------

    /**
     * Calculate the fee which will be charged by GoCardless
     *
     * @param int $iAmount The amount of the transaction
     *
     * @return int
     */
    protected function calculateFee(int $iAmount): int
    {
        /**
         * As of 17/03/2015 there is no API method or property describing the fee which GoCardless will charge
         * However, their charging mechanic is simple: 1% of the total transaction (rounded up to nearest penny)
         * and capped at £2.
         *
         * Until such an API method exists, we'll calculate it ourselves - it should be accurate. Famous last words...
         */

        $iFee = intval(ceil($iAmount * 0.01));
        $iFee = $iFee > 200 ? 200 : $iFee;
        return $iFee;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string                        $sTransactionId The transaction's ID
     * @param int                           $iAmount        The amount to refund
     * @param Currency                      $oCurrency      The currency in which to refund
     * @param Resource\Invoice\Data\Payment $oPaymentData   The payment data object
     * @param string                        $sReason        The refund's reason
     * @param Resource\Payment              $oPayment       The payment object
     * @param Resource\Refund               $oRefund        The refund object
     * @param Resource\Invoice              $oInvoice       The invoice object
     *
     * @return RefundResponse
     * @throws ResponseException
     * @throws FactoryException
     */
    public function refund(
        string $sTransactionId,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sReason,
        Resource\Payment $oPayment,
        Resource\Refund $oRefund,
        Resource\Invoice $oInvoice
    ): RefundResponse {

        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Constants::MODULE_SLUG);

        //  Bail out on GoCardless refunds until we have an actual need (and can test it properly)
        $oRefundResponse->setStatusFailed(
            'GoCardless refunds are not available right now.',
            null,
            'GoCardless refunds are not available right now.'
        );

        return $oRefundResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param Resource\Source $oResource The Source object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {

        $sMandateId = getFromArray('mandate_id', $aData);
        if (empty($sMandateId)) {
            throw new DriverException('"mandate_id" must be supplied when creating a GoCardless payment source.');
        }

        try {

            $oClient  = $this->getClient();
            $oMandate = $oClient
                ->mandates()
                ->get($sMandateId);

            if (empty($oResource->label)) {
                $oBankAccount = $oClient
                    ->customerBankAccounts()
                    ->get($oMandate->links->customer_bank_account);

                $oResource->label = sprintf(
                    'Direct Debit (%s account ending %s)',
                    $oBankAccount->bank_name,
                    $oBankAccount->account_number_ending
                );
            }

        } catch (Exception $e) {
            throw new DriverException(
                '"' . $sMandateId . '" is not a valid mandate ID.',
                $e->getCode(),
                $e
            );
        }

        $oResource->data = (object) [
            'mandate_id' => $sMandateId,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a payment source on the gateway
     *
     * @param Resource\Source $oResource The Resource being updated
     *
     * @throws NailsException
     */
    public function updateSource(
        Resource\Source $oResource
    ): void {
        //  @todo (Pablo - 2019-10-09) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a payment source from the gateway
     *
     * @param Resource\Source $oResource The Resource being deleted
     *
     * @throws NailsException
     */
    public function deleteSource(
        Resource\Source $oResource
    ): void {
        //  @todo (Pablo - 2019-10-09) - implement this
        throw new NailsException('Method not implemented');
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for creating a new customer on the gateway
     *
     * @param array $aData The driver specific customer data
     *
     * @return GoCardlessPro\Resources\Customer
     * @throws DriverException
     * @throws NailsException
     * @throws GoCardlessPro\Core\Exception\InvalidStateException
     */
    public function createCustomer(array $aData = []): GoCardlessPro\Resources\Customer
    {
        if (empty($aData['company_name']) && empty($aData['given_name']) && empty($aData['family_name'])) {
            throw new DriverException(
                'At least one must be supplied: "company_name", or "given_name" and "family_name"'
            );
        } elseif (empty($aData['company_name']) && (empty($aData['given_name']) || empty($aData['family_name']))) {
            throw new DriverException(
                'Both "given_name" and "family_name" must be supplied'
            );
        }

        $oClient = $this->getClient();
        return $oClient
            ->customers()
            ->create([
                'params' => $aData,
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     *
     * @return GoCardlessPro\Resources\Customer
     * @throws DriverException
     */
    public function getCustomer($mCustomerId, array $aData = []): GoCardlessPro\Resources\Customer
    {
        $oClient = $this->getClient();
        return $oClient
            ->customers()
            ->get($mCustomerId, $aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     *
     * @return GoCardlessPro\Resources\Customer
     * @throws DriverException
     */
    public function updateCustomer($mCustomerId, array $aData = [])
    {
        $oClient = $this->getClient();
        return $oClient
            ->customers()
            ->update(
                $mCustomerId,
                [
                    'params' => $aData,
                ]
            );
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     *
     * @throws NailsException
     */
    public function deleteCustomer($mCustomerId): void
    {
        throw new NailsException('Method not supported by GoCardless SDK');
    }
}
