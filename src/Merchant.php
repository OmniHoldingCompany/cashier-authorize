<?php

namespace Laravel\CashierAuthorizeNet;

trait Merchant
{
    /**
     * Override environment credential variables with merchant credentials for multi-tenancy
     */
    public function getMerchantApi()
    {
        $loader = new \Dotenv\Loader('notreal');
        $loader->setEnvironmentVariable('ADN_API_LOGIN_ID', $this->adn_api_login_id);
        $loader->setEnvironmentVariable('ADN_TRANSACTION_KEY', $this->adn_transaction_key);
        $loader->setEnvironmentVariable('ADN_SECRET_KEY', $this->adn_secret_key);

        return new MerchantApi();
    }

    /**
     * Override environment credential variables with merchant credentials for multi-tenancy
     */
    public function getCustomerApi()
    {
        $loader = new \Dotenv\Loader('notreal');
        $loader->setEnvironmentVariable('ADN_API_LOGIN_ID', $this->adn_api_login_id);
        $loader->setEnvironmentVariable('ADN_TRANSACTION_KEY', $this->adn_transaction_key);
        $loader->setEnvironmentVariable('ADN_SECRET_KEY', $this->adn_secret_key);

        return new CustomerApi();
    }

    /**
     * Override environment credential variables with merchant credentials for multi-tenancy
     */
    public function getTransactionApi()
    {
        $loader = new \Dotenv\Loader('notreal');
        $loader->setEnvironmentVariable('ADN_API_LOGIN_ID', $this->adn_api_login_id);
        $loader->setEnvironmentVariable('ADN_TRANSACTION_KEY', $this->adn_transaction_key);
        $loader->setEnvironmentVariable('ADN_SECRET_KEY', $this->adn_secret_key);

        return new TransactionApi();
    }
}
