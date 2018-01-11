<?php

namespace Laravel\CashierAuthorizeNet;

use net\authorize\api\contract\v1 as AnetAPI;

/**
 * Class AuthorizeApi
 * @package Laravel\CashierAuthorizeNet
 */
class AuthorizeApi
{
    /**
     * Authorize.net authenticator
     *
     * @var AnetAPI\MerchantAuthenticationType
     */
    private $merchantAuthentication;

    public function __construct()
    {
        $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $this->merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $this->merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));
    }
}
