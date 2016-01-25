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

use Nails\Factory;
use Nails\Environment;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;

class GoCardless extends PaymentBase
{
    protected $sMandateTable = NAILS_DB_PREFIX . 'user_meta_invoice_gocardless_mandate';
    protected $aMandates;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct();

        //  Get any mandates this user might have
        if (isLoggedIn()) {

            $oUserMeta       = Factory::model('UserMeta', 'nailsapp/module-auth');
            $this->aMandates = $oUserMeta->getMany($this->sMandateTable, activeUser('id'));

        } else {

            $this->aMandates = array();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver is available to be used against the selected iinvoice
     * @return boolean
     */
    public function isAvailable()
    {
        // This driver can only be used with logged in users
        return isLoggedIn();
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     * @return boolean
     */
    public function isRedirect()
    {
        return empty($this->aMandates);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, 'CARD' for basic credit
     * card details.
     * @return mixed
     */
    public function getPaymentFields()
    {
        if (!empty($this->aMandates) && count($this->aMandates) > 1) {

            $aOptions = array(
                '' => 'Please choose'
            );
            foreach ($this->aMandates as $oMandate) {
                $aOptions[$oMandate->id] = $oMandate->label;
            }

            return array(
                array(
                    'key'      => 'mandate_id',
                    'type'     => 'dropdown',
                    'label'    => 'Mandate',
                    'required' => true,
                    'options'  => $aOptions
                )
            );

        } else {

            return array();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     * @param  integer   $iAmount      The payment amount
     * @param  string    $sCurrency    The payment currency
     * @param  array     $aData        An array of driver data
     * @param  string    $sDescription The charge description
     * @param  \stdClass $oPayment     The payment object
     * @param  \stdClass $oInvoice     The invoice object
     * @param  string    $sSuccessUrl  The URL to go to after successfull payment
     * @param  string    $sFailUrl     The URL to go to after failed payment
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge(
        $iAmount,
        $sCurrency,
        $aData,
        $sDescription,
        $oPayment,
        $oInvoice,
        $sSuccessUrl,
        $sFailUrl
    )
    {
        $oChargeResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            if (Environment::is('PRODUCTION')) {

                $sAccessToken = $this->getSetting('sAccessTokenLive');
                $sEnvironment = \GoCardlessPro\Environment::LIVE;

            } else {

                $sAccessToken = $this->getSetting('sAccessTokenSandbox');
                $sEnvironment = \GoCardlessPro\Environment::SANDBOX;
            }

            if (empty($sAccessToken)) {
                throw new DriverException('Missing GoCardless Access Token.', 1);
            }

            $oClient = new \GoCardlessPro\Client(
                array(
                    'access_token' => $sAccessToken,
                    'environment'  => $sEnvironment
                )
            );

            /**
             * What we do here depends on a number of things:
             * - If we have 0 mandates, then we're using a redirect flow
             * - If we have 1 mandate then we're using it
             * - If we have > 1 mandate then we're using the one supplied by the user
             */

            if (empty($this->aMandates)) {

                //  Create a new redirect flow
                $oGCResponse = $oClient->redirectFlows()->create(
                    array(
                        'params' => array(
                            'session_token'        => 'xxx',
                            'success_redirect_url' => $sSuccessUrl
                        )
                    )
                );

                if ($oGCResponse->api_response->status_code === 201) {

                    $oChargeResponse->setRedirectUrl(
                        $oGCResponse->api_response->body->redirect_flows->redirect_url
                    );

                } else {

                    //  @todo: handle errors returned by the GoCardless Client/API
                    $oChargeResponse->setStatusFailed(
                        null,
                        0,
                        'The gateway rejected the request, you may wish to try again.'
                    );
                }

            } else {

                if (count($this->aMandates) === 1) {

                    $sMandateId = $this->aMandates[0]->mandate_id;

                } elseif (!empty($aData['mandate_id'])) {

                    foreach ($this->aMandates as $oMandate) {
                        if ($oMandate->mandate_id == $aData['mandate_id']) {
                            $sMandateId = $aData['mandate_id'];
                        }
                    }
                }

                if (empty($sMandateId)) {
                    throw new DriverException('Missing Mandate ID.', 1);
                }

                //  Create a payment against the mandate
                $sTxnId = $this->createPayment($oClient, $sMandateId, $iAmount, $sCurrency);

                if (!empty($sTxnId)) {

                    //  Set the response as processing, GoCardless will let us know when the payment is complete
                    $oChargeResponse->setStatusProcessing();
                    $oChargeResponse->setTxnId($sTxnId);

                } else {

                    $oChargeResponse->setStatusFailed(
                        null,
                        0,
                        'The gateway rejected the request, you may wish to try again.'
                    );
                }
            }

        } catch (\GoCardlessPro\Core\Exception\ApiConnectionException $e) {

            //  Network error
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\GoCardlessPro\Core\Exception\ApiException $e) {

            //  API request failed / record couldn't be created.
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (\GoCardlessPro\Core\Exception\MalformedResponseException $e) {

            //  Unexpected non-JSON response
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway returned a malformed response, you may wish to try again.'
            );

        } catch (\Exception $e) {

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
     * Complete the payment
     * @param  \stdClass $oPayment  The Payment object
     * @param  \stdClass $oInvoice  The Invoice object
     * @param  array     $aGetVars  Any $_GET variables passed from the redirect flow
     * @return \Nails\Invoice\Model\CompleteResponse
     */
    public function complete($oPayment, $oInvoice, $aGetVars)
    {
        $oCompleteResponse = Factory::factory('CompleteResponse', 'nailsapp/module-invoice');

        try {

            if (Environment::is('PRODUCTION')) {

                $sAccessToken = $this->getSetting('sAccessTokenLive');
                $sEnvironment = \GoCardlessPro\Environment::LIVE;

            } else {

                $sAccessToken = $this->getSetting('sAccessTokenSandbox');
                $sEnvironment = \GoCardlessPro\Environment::SANDBOX;
            }

            if (empty($sAccessToken)) {
                throw new DriverException('Missing GoCardless Access Token.', 1);
            }

            $oClient = new \GoCardlessPro\Client(
                array(
                    'access_token' => $sAccessToken,
                    'environment'  => $sEnvironment
                )
            );

            $sRedirectFlowId = !empty($aGetVars['redirect_flow_id']) ? $aGetVars['redirect_flow_id'] : null;

            if (empty($sRedirectFlowId)) {

                $oCompleteResponse->setStatusFailed(
                    'The complete request was missing $_GET[\'redirect_flow_id\']',
                    0,
                    'The request failed to complete, data was missing.'
                );

            } else {

                //  Complete the redirect flow
                $oGCResponse = $oClient->redirectFlows()->complete(
                    $sRedirectFlowId,
                    array(
                        'params' => array(
                            'session_token' => 'xxx'
                        )
                    )
                );

                if ($oGCResponse->api_response->status_code === 200) {

                    //  Save the mandate against user meta
                    $oUserMeta  = Factory::model('UserMeta', 'nailsapp/module-auth');
                    $oNow       = Factory::factory('DateTime');
                    $sMandateId = $oGCResponse->api_response->body->redirect_flows->links->mandate;

                    $oUserMeta->update(
                        $this->sMandateTable,
                        activeUser('id'),
                        array(
                            'label'      => 'Direct Debit Mandate (Created ' . $oNow->format('jS F, Y') . ')',
                            'mandate_id' => $sMandateId,
                            'created'    => $oNow->format('Y-m-d H:i:s')
                        )
                    );

                    //  Create a payment against the mandate
                    $sTxnId = $this->createPayment($oClient, $sMandateId, $oPayment->amount->base, $oPayment->currency);

                    if (!empty($sTxnId)) {

                        //  Set the response as processing, GoCardless will let us know when the payment is complete
                        $oCompleteResponse->setStatusProcessing();
                        $oCompleteResponse->setTxnId($sTxnId);

                    } else {

                        $oCompleteResponse->setStatusFailed(
                            null,
                            0,
                            'The gateway rejected the request, you may wish to try again.'
                        );
                    }

                } else {

                    //  @todo: handle errors returned by the GoCardless Client/API
                    $oCompleteResponse->setStatusFailed(
                        null,
                        0,
                        'The gateway rejected the request, you may wish to try again.'
                    );
                }
            }

        } catch (\GoCardlessPro\Core\Exception\ApiConnectionException $e) {

            //  Network error
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\GoCardlessPro\Core\Exception\ApiException $e) {

            //  API request failed / record couldn't be created.
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (\GoCardlessPro\Core\Exception\MalformedResponseException $e) {

            //  Unexpected non-JSON response
            $oCompleteResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway returned a malformed response, you may wish to try again.'
            );

        } catch (\Exception $e) {

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
     * @param  \GoCardlessPro\Client $oClient    The GoCardless client
     * @param  string                $sMandateId The mandate ID
     * @param  integer               $iAmount    The amount of the payment
     * @param  string                $sCurrency  The currency in which to take payment
     * @return string
     */
    protected function createPayment($oClient, $sMandateId, $iAmount, $sCurrency)
    {
        $oGCResponse = $oClient->payments()->create(
            array(
                'params' => array(
                    'amount'   => $iAmount,
                    'currency' => $sCurrency,
                    'links' => array(
                        'mandate' => $sMandateId
                    )
                )
            )
        );

        $sTxnId = null;

        if ($oGCResponse->api_response->status_code === 201) {
            $sTxnId = $oGCResponse->api_response->body->payments->id;
        }

        return $sTxnId;
    }
}
