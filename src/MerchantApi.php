<?php

namespace Laravel\CashierAuthorizeNet;

use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class AuthorizeApi
 * @package Laravel\CashierAuthorizeNet
 */
class MerchantApi
{
    /**
     * Authorize.net authenticator
     *
     * @var AnetAPI\MerchantAuthenticationType
     */
    protected $merchantAuthentication;

    public function __construct()
    {
        $this->merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $this->merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $this->merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));
    }

    /**
     * Retrieve all existing customer profile IDs.
     *
     * @return array
     * @throws \Exception
     */
    public function listCustomerProfileIds()
    {
        $request = new AnetAPI\GetCustomerProfileIdsRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);

        $controller = new AnetController\GetCustomerProfileIdsController($request);

        /** @var AnetAPI\GetCustomerProfileIdsResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00001': // An error occurred during processing. Please try again.
                case 'E00053': // The server is currently too busy, please try again later.
                case 'E00104': // The server is in maintenance, so the requested method is unavailable, please try again later.
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
                    break;
            }
        }

        return $response->getIds();
    }

    /**
     * Get detailed information about a specific transaction.
     *
     * @return AnetAPI\TransactionDetailsType
     * @throws \Exception
     */
    public function getTransactionDetails($transactionId)
    {
        $request = new AnetAPI\GetTransactionDetailsRequest();
        $request->setTransId($transactionId);
        $request->setMerchantAuthentication($this->merchantAuthentication);

        $controller = new AnetController\GetTransactionDetailsController($request);

        /** @var AnetAPI\GetTransactionDetailsResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00001': // An error occurred during processing. Please try again.
                case 'E00040': // The Record cannot be found.
                case 'E00053': // The server is currently too busy, please try again later.
                case 'E00104': // The server is in maintenance, so the requested method is unavailable, please try again later.
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
                    break;
            }
        }

        return $response->getTransaction();
    }
}
