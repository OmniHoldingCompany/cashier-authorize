<?php

namespace Laravel\CashierAuthorizeNet;

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment as ANetEnvironment;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class CustomerApi
 * @package Laravel\CashierAuthorizeNet
 */
class CustomerApi extends AuthorizeApi
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

    /********************
     * CUSTOMER PROFILE *
     ********************/

    /**
     * Create a new customer profile.
     *
     * @param array $profileDetails
     *
     * @return integer Customer profile ID
     * @throws \Exception
     */
    public function createCustomerProfile($profileDetails = [])
    {
        if (is_null($profileDetails['merchant_customer_id']) && is_null($profileDetails['email']) && is_null($profileDetails['description'])) {
            throw new \Exception('Must provide at least one of the following: merchantCustomerId, email, description');
        }

        $customerProfile = new AnetAPI\CustomerProfileType();
        self::setProfileDetails($customerProfile, $profileDetails);

        $request = new AnetAPI\CreateCustomerProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setProfile($customerProfile);

        $controller = new AnetController\CreateCustomerProfileController($request);

        /** @var AnetAPI\CreateCustomerPaymentProfileResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00001': // An error occurred during processing. Please try again.
                case 'E00039': // A duplicate record already exists.
                case 'E00053': // The server is currently too busy, please try again later.
                case 'E00104': // The server is in maintenance, so the requested method is unavailable, please try again later.
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        return $response->getCustomerProfileId();
    }

    /**
     * Retrieve an existing customer profile along with all the associated payment profiles and shipping addresses.
     *
     * @param interger $customerProfileId
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfile($customerDetails)
    {
        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);

        if (isset($customerDetails['merchant_customer_id'])) {
            $request->setMerchantCustomerId($customerDetails['merchant_customer_id']);
        }
        if (isset($customerDetails['customer_profile_id'])) {
            $request->setCustomerProfileId($customerDetails['customer_profile_id']);
        }
        if (isset($customerDetails['email'])) {
            $request->setEmail($customerDetails['email']);
        }

        $controller = new AnetController\GetCustomerProfileController($request);

        /** @var AnetAPI\GetCustomerProfileResponse $response */
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

        /** @var AnetAPI\CustomerProfileMaskedType $profileSelected */
        $profileSelected = $response->getProfile();

        return $profileSelected;
    }

    /**
     * Update an existing customer profile.
     *
     * @param integer $customerProfileId
     * @param array    $profileDetails
     *
     * @return boolean
     * @throws \Exception
     */
    public function updateCustomerProfile($customerProfileId, $profileDetails)
    {
        $customerProfile = new AnetAPI\CustomerProfileExType();
        $customerProfile->setCustomerProfileId($customerProfileId);
        self::setProfileDetails($customerProfile, $profileDetails);

        $request = new AnetAPI\UpdateCustomerProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setProfile($customerProfile);

        $controller = new AnetController\UpdateCustomerProfileController($request);
        /** @var AnetAPI\UpdateCustomerPaymentProfileResponse $response */
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

        return true;
    }

    /**
     * Delete an existing customer profile along with all associated
     * customer payment profiles and customer shipping addresses.
     *
     * @param integer $customerProfileId
     *
     * @return boolean
     * @throws \Exception
     */
    public function deleteCustomerProfile($customerProfileId)
    {
        $request = new AnetAPI\DeleteCustomerProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setCustomerProfileId($customerProfileId);

        $controller = new AnetController\DeleteCustomerProfileController($request);
        /** @var AnetAPI\UpdateCustomerPaymentProfileResponse $response */
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

        return true;
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

    /*******************
     * PAYMENT PROFILE *
     *******************/

    /**
     * Create a new customer payment profile for an existing customer profile.
     *
     * @param integer                            $customerProfileId
     * @param AnetAPI\CustomerPaymentProfileType $paymentProfile
     *
     * @return string
     * @throws \Exception
     */
    public function addPaymentProfile($customerProfileId, AnetAPI\CustomerPaymentProfileType $paymentProfile)
    {
        $paymentProfileRequest = new AnetAPI\CreateCustomerPaymentProfileRequest();
        $paymentProfileRequest->setMerchantAuthentication($this->merchantAuthentication);
        $paymentProfileRequest->setCustomerProfileId($customerProfileId);
        $paymentProfileRequest->setPaymentProfile($paymentProfile);
        //$paymentProfileRequest->setValidationMode("liveMode");

        $controller = new AnetController\CreateCustomerPaymentProfileController($paymentProfileRequest);
        /** @var AnetAPI\CreateCustomerPaymentProfileResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00001': // An error occurred during processing. Please try again.
                case 'E00039': // A duplicate record already exists.
                case 'E00040': // The Record cannot be found.
                case 'E00042': // You cannot add more than {0} payment profiles.
                case 'E00053': // The server is currently too busy, please try again later.
                case 'E00083': // Bank payment method is not accepted for the selected business country.
                case 'E00084': // Credit card payment method is not accepted for the selected business country.
                case 'E00085': // State is not valid.
                case 'E00104': // The server is in maintenance, so the requested method is unavailable, please try again later.
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        return $response->getCustomerPaymentProfileId();
    }

    /**
     * Retrieve the details of a customer payment profile associated with an existing customer profile.
     *
     * @param integer $customerProfileId
     * @param integer $paymentProfileId
     *
     * @return AnetAPI\CustomerPaymentProfileMaskedType
     * @throws \Exception
     */
    public function getPaymentProfile($customerProfileId, $paymentProfileId)
    {
        $request = new AnetAPI\GetCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setCustomerProfileId($customerProfileId);
        $request->setCustomerPaymentProfileId($paymentProfileId);

        $controller = new AnetController\GetCustomerPaymentProfileController($request);

        /** @var AnetAPI\GetCustomerPaymentProfileResponse $response */
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
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        return $response->getPaymentProfile();
    }

    /**
     * Delete an existing customer profile along with all associated customer payment profiles and customer shipping
     * addresses.
     *
     * @param integer $customerProfileId
     * @param integer $paymentProfileId
     *
     * @return boolean
     * @throws \Exception
     */
    public function deletePaymentProfile($customerProfileId, $paymentProfileId)
    {
        $request = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->merchantAuthentication);
        $request->setCustomerProfileId($customerProfileId);
        $request->setCustomerPaymentProfileId($paymentProfileId);

        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);

        /** @var AnetAPI\DeleteCustomerPaymentProfileResponse $response */
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
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        return true;
    }


    /********************
     * HELPER FUNCTIONS *
     ********************/

    /**
     * @param AnetAPI\CustomerProfileBaseType $customerProfile
     * @param array                       $profileDetails
     *
     * @return AnetAPI\CustomerProfileBaseType
     */
    private static function setProfileDetails(&$customerProfile, $profileDetails)
    {
        if (isset($profileDetails['merchant_customer_id'])) {
            $customerProfile->setMerchantCustomerId($profileDetails['merchant_customer_id']);
        }

        if (isset($profileDetails['email'])) {
            $customerProfile->setEmail($profileDetails['email']);
        }

        if (isset($profileDetails['description'])) {
            $customerProfile->setDescription($profileDetails['description']);
        }

        return $customerProfile;
    }

    /**
     * Build a new payment profile that can be added to a customer profile.
     *
     * @param AnetAPI\PaymentType         $paymentType
     * @param AnetApi\CustomerAddressType $billTo
     * @param string                      $customerType
     * @param boolean                     $default
     *
     * @return AnetAPI\CustomerPaymentProfileType
     * @throws \Exception
     */
    public static function buildPaymentProfile($paymentType, $billTo, $customerType = 'individual', $default = false)
    {
        $paymentProfile = new AnetAPI\CustomerPaymentProfileExType();
        $paymentProfile->setPayment($paymentType);
        $paymentProfile->setBillTo($billTo);
        $paymentProfile->setCustomerType($customerType);
        $paymentProfile->setDefaultPaymentProfile($default);

        return $paymentProfile;
    }

    /**
     * @param array $cardDetails
     *
     * @return AnetAPI\PaymentType
     */
    public static function getCreditCardPaymentType($cardDetails)
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($cardDetails['number']);

        if (!empty($cardDetails['expiration'])) {
            $creditCard->setExpirationDate($cardDetails['expiration']);
        }

        if (!empty($cardDetails['cvv'])) {
            $creditCard->setCardCode($cardDetails['cvv']);
        }

        $paymentDetails = new AnetAPI\PaymentType();
        $paymentDetails->setCreditCard($creditCard);

        return $paymentDetails;
    }

    /**
     * @param array $billingDetails
     *
     * @return AnetAPI\CustomerAddressType
     */
    public static function getBillingObject($billingDetails)
    {
        $billTo = new AnetAPI\CustomerAddressType();

        $billTo->setFirstName($billingDetails['first_name']);
        $billTo->setLastName($billingDetails['last_name']);
        $billTo->setAddress($billingDetails['address_1']);
        $billTo->setCity($billingDetails['city']);
        $billTo->setState($billingDetails['state']);
        $billTo->setZip($billingDetails['zip']);
        $billTo->setCountry($billingDetails['country']);

        return $billTo;
    }
}
