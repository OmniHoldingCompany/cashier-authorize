<?php

namespace Laravel\CashierAuthorizeNet;

use net\authorize\api\contract\v1 as AnetAPI;

class AuthorizeMerchant
{
    /**
     * Authorize.net authenticator
     *
     * @var AnetAPI\MerchantAuthenticationType
     */
    protected $merchantAuthentication;

    public function __construct(AnetAPI\MerchantAuthenticationType $merchantAuthentication = null)
    {
        $this->merchantAuthentication = $merchantAuthentication ?? $this->getMerchantAuthentication();
    }

    protected function getMerchantAuthentication()
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));

        return $merchantAuthentication;
    }
}
