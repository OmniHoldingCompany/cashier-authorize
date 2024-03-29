<?php

namespace Laravel\CashierAuthorizeNet;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\contract\v1\GetCustomerProfileRequest;
use net\authorize\api\contract\v1\TransactionRequestType;
use net\authorize\api\controller as AnetController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait Billable
{
    public static function getMerchantAuthentication()
    {
        /* Create a merchantAuthenticationType object with authentication details
         retrieved from the constants file */
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));

        return $merchantAuthentication;
    }

    protected function getBillingObject($data = [])
    {
        $billto = new AnetAPI\CustomerAddressType();
        if (!empty($data['first_name'])) {
            $billto->setFirstName($data['first_name']);
            $billto->setLastName($data['last_name']);
            $billto->setAddress($data['address_1']);
            $billto->setCity($data['city']);
            $billto->setState($data['state']);
            $billto->setZip($data['zip']);
            $billto->setCountry($data['country']);
        } else {
            $billto->setFirstName($this->first_name);
            $billto->setLastName($this->last_name);
            $billto->setAddress($this->address_1);
            $billto->setCity($this->city);
            $billto->setState($this->state);
            $billto->setZip($this->zip);
            $billto->setCountry($this->country);
        }

        return $billto;
    }

    /**
     * Create an Authorize customer for the given user.
     *
     * @throws \Exception
     */
    public function initializeCustomerProfile($merchantId = null)
    {
        $this->setAuthorizeAccount();

        $customerprofile = new AnetAPI\CustomerProfileType();
        $customerprofile->setMerchantCustomerId($merchantId ?? $this->id);
        $customerprofile->setEmail($this->email);

        $requestor = new Requestor();
        $request   = $requestor->prepare(new AnetAPI\CreateCustomerProfileRequest());
        $request->setProfile($customerprofile);

        $controller = new AnetController\CreateCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();

            throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
        }

        $authorizeId                 = $this->authorize_id = $response->getCustomerProfileId();
        $this->authorize_merchant_id = $customerprofile->getMerchantCustomerId();
        $this->save();

        return $authorizeId;
    }

    /**
     * Create an Authorize customer for the given user.
     *
     * @throws \Exception
     */
    public function updateCustomerEmail()
    {
        $this->setAuthorizeAccount();

        $customerProfile = new AnetAPI\CustomerProfileExType();
        $customerProfile->setCustomerProfileId($this->getAuthorizeId());

        $customerProfile->setEmail($this->email);

        $requestor = new Requestor();
        $request   = $requestor->prepare(new AnetAPI\UpdateCustomerProfileRequest());
        $request->setProfile($customerProfile);

        $controller = new AnetController\UpdateCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
        }
    }

    /**
     * Create an Authorize customer for the given user.
     *
     * @throws \Exception
     */
    public function syncMerchantId()
    {
        $this->setAuthorizeAccount();

        $customerProfile = new AnetAPI\CustomerProfileExType();
        $customerProfile->setCustomerProfileId($this->getAuthorizeId());

        $customerProfile->setMerchantCustomerId($this->id);

        $requestor = new Requestor();
        $request   = $requestor->prepare(new AnetAPI\UpdateCustomerProfileRequest());
        $request->setProfile($customerProfile);

        $controller = new AnetController\UpdateCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
        }
        $this->authorize_merchant_id = $customerProfile->getMerchantCustomerId();
        $this->save();
    }

    /**
     * Create an Authorize customer for the given user.
     *
     * @param array $options
     *
     * @return integer payment profile ID
     *
     * @throws \Exception
     */
    public function addPaymentMethodToCustomer($cardDetails, $options = [])
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $paymentDetails = self::getPaymentDetails($cardDetails);

        $billto = $this->getBillingObject($options);

        // Create a new Customer Payment Profile object
        $paymentprofile = new AnetAPI\CustomerPaymentProfileType();
        $paymentprofile->setCustomerType('individual');
        $paymentprofile->setBillTo($billto);
        $paymentprofile->setPayment($paymentDetails);
        $paymentprofile->setDefaultPaymentProfile(true);

        $paymentprofiles[] = $paymentprofile;

        // Assemble the complete transaction request
        $paymentprofilerequest = new AnetAPI\CreateCustomerPaymentProfileRequest();
        $paymentprofilerequest->setMerchantAuthentication($merchantAuthentication);

        // Add an existing profile id to the request
        $paymentprofilerequest->setCustomerProfileId($this->getAuthorizeId());
        $paymentprofilerequest->setPaymentProfile($paymentprofile);
        //$paymentprofilerequest->setValidationMode("liveMode");

        // Create the controller and get the response
        $controller = new AnetController\CreateCustomerPaymentProfileController($paymentprofilerequest);
        $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00039':
                case 'E00040':
                case 'E00042':
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
     * @param string $profileId
     *
     * @return mixed
     * @throws \Exception
     */
    public function getCustomerProfileByProfileId($profileId = null)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($profileId ?? $this->getAuthorizeId());

        $profileSelected = self::getCustomerProfile($request);

        return $profileSelected;
    }

    /**
     * @param string $email
     *
     * @return mixed
     * @throws \Exception
     */
    public function getCustomerProfileByEmail($email = null)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setEmail($email ?? $this->email);

        $profileSelected = self::getCustomerProfile($request);

        return $profileSelected;
    }

    /**
     * @param string $merchantId
     *
     * @return mixed
     * @throws \Exception
     */
    public function getCustomerProfileByMerchantId($merchantId = null)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setMerchantCustomerId($merchantId ?? $this->getAuthorizeMerchantId());

        $profileSelected = self::getCustomerProfile($request);

        return $profileSelected;
    }

    /**
     * @param GetCustomerProfileRequest $request
     *
     * @return mixed
     * @throws \Exception
     */
    private static function getCustomerProfile(GetCustomerProfileRequest $request)
    {
        $controller = new AnetController\GetCustomerProfileController($request);

        $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00040':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        $profileSelected = $response->getProfile();

        return $profileSelected;
    }

    public function getAuthorizeId()
    {
        if (is_null($this->authorize_id)) {
            try {
                $authorizeCustomerProfile = $this->getCustomerProfileByMerchantId();

                $this->authorize_id = $authorizeCustomerProfile->getCustomerProfileId();
                $this->save();
            } catch (BadRequestHttpException $e) {
                $this->initializeCustomerProfile();
            }
        }

        return $this->authorize_id;
    }

    public function getAuthorizeMerchantId()
    {
        if (is_null($this->authorize_merchant_id)) {
            try {
                $authorizeCustomerProfile = $this->getCustomerProfileByEmail();

                $this->authorize_merchant_id = $authorizeCustomerProfile->getMerchantCustomerId();
                $this->save();
            } catch (BadRequestHttpException $e) {
                $this->initializeCustomerProfile();
            }
        }

        return $this->authorize_merchant_id;
    }

    public function getAuthorizePaymentId()
    {
        if (is_null($this->authorize_payment_id)) {
            if ($this->authorize_merchant_id !== $this->id) {
                // Only grab the first payment profile if membership was migrated from ZingFit
                $paymentProfiles = $this->getCustomerPaymentProfiles();
                if (count($paymentProfiles) <= 0) {
                    throw new \Exception('No payment profile available for membership renewal', 500);
                }

                $this->syncMerchantId();
                $this->authorize_payment_id = $paymentProfiles[0]['id'];
                $this->save();
            } else {
                throw new \Exception('Unable to find payment profile for membership renewal', 500);
            }
        }

        return $this->authorize_payment_id;
    }

    public function getCustomerPaymentProfiles()
    {
        $profile         = $this->getCustomerProfileByProfileId();
        $paymentProfiles = $profile->getPaymentProfiles();

        $paymentMethods = [];

        foreach ($paymentProfiles as $profile) {
            $card = $profile->getPayment()->getCreditCard();

            $paymentMethods[] = [
                'id'         => $profile->getCustomerPaymentProfileId(),
                'number'     => $card->getCardNumber(),
                'expiration' => $card->getExpirationDate(),
                'type'       => $card->getCardType(),
            ];
        }

        return $paymentMethods;
    }

    /**
     * @param string $customerpaymentprofileid
     *
     * @return $this
     * @throws \Exception
     */
    public function deleteCustomerPaymentProfile($customerpaymentprofileid)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        // Use an existing payment profile ID for this Merchant name and Transaction key
        $request = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($this->getAuthorizeId());
        $request->setCustomerPaymentProfileId($customerpaymentprofileid);
        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);
        $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00040':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        return $this;
    }

    /**
     * Delete an Authorize.net Profile
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteAuthorizeProfile()
    {
        $requestor = new Requestor();
        $request   = $requestor->prepare((new AnetAPI\DeleteCustomerProfileRequest()));
        $request->setCustomerProfileId($this->getAuthorizeId());

        $controller = new AnetController\DeleteCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response) || $response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText(), 500);
        }

        return true;
    }

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int   $amount
     * @param  int   $paymentProfileId
     * @param  array $options
     *
     * @return array
     * @throws \Exception
     */
    public function charge($amount, $paymentProfileId, array $options = [])
    {
        if ($amount <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $options = array_merge([
            'currency' => self::preferredCurrency(),
        ], $options);

        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($this->getAuthorizeId());
        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($paymentProfileId);
        $profileToCharge->setPaymentProfile($paymentProfile);

        $order = new AnetAPI\OrderType;
        $order->setDescription($options['description']);

        $transactionRequest = self::createTransactionRequest("authCaptureTransaction", $amount);

        $transactionRequest->setCurrencyCode($options['currency']);
        $transactionRequest->setOrder($order);
        $transactionRequest->setProfile($profileToCharge);

        $response = self::buildAndExecuteRequest($transactionRequest);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00084':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                case 'E00027':
                    // Transaction error caught below
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getText());
                    break;
            }
        }

        $tresponse = $response->getTransactionResponse();

        if (is_null($tresponse)) {
            throw new \Exception('ERROR: NO TRANSACTION RESPONSE', 500);
        }

        switch ($tresponse->getResponseCode()) {
            case "1":
                // Success
                break;

            case "2":
                // General decline, no further details.
                throw new BadRequestHttpException($tresponse->getResponseText());
                break;
            case "3":
                // A referral to a voice authorization center was received.
                // Please call the appropriate number below for a voice authorization.
                //   For American Express call: (800) 528-2121
                //   For Diners Club call: (800) 525-9040
                //   For Discover/Novus call: (800) 347-1111
                //   For JCB call : (800) 522-9345
                //   For Visa/Mastercard call: (800) 228-1122
                // Once an authorization is issued, you can then submit the transaction through your Virtual Terminal as a Capture Only transaction.
                throw new BadRequestHttpException($tresponse->getResponseText() . ' Voice authorization required.');
                break;
            case "4":
                throw new BadRequestHttpException($tresponse->getResponseText() . ' The code returned from the processor indicating that the card used needs to be picked up.');
                break;
            case "11":
                // A transaction with identical amount and credit card information was submitted within the previous two minutes.
                throw new BadRequestHttpException($tresponse->getResponseText());
                break;

            default:
                throw new \Exception('Unknown response code: ' . $tresponse->getResponseCode());
        }

        return [
            'authCode' => $tresponse->getAuthCode(),
            'transId'  => $tresponse->getTransId(),
        ];
    }

    /**
     * Return money to a credit card.
     *
     * @param  array   $cardDetails Credit cards details
     * @param  integer $amount      Amount to be refunded, in cents
     *
     * @return integer ADN Transaction ID
     * @throws \Exception
     */
    public function refund($cardDetails, $amount)
    {
        if ($amount <= 0) {
            throw new \Exception('Refund amount must be greater than 0');
        }

        $paymentDetails = $this->getPaymentDetails($cardDetails);

        $transactionRequest = $this->createTransactionRequest("refundTransaction", $amount);
        $transactionRequest->setPayment($paymentDetails);

        $response = $this->buildAndExecuteRequest($transactionRequest);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", 500);
        }
        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00105':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                case 'E00027':
                    // Transaction error caught below
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        $tresponse = $response->getTransactionResponse();

        if (is_null($tresponse)) {
            throw new \Exception('ERROR: NO TRANSACTION RESPONSE', 1);
        }

        $errorMessages = $tresponse->getErrors();

        if (is_null($tresponse->getErrors())) {
            throw new \Exception($errorMessages[0]->getCode() . ': ' . $errorMessages[0]->getErrorText(), 500);
        }

        return $tresponse->getTransId();
    }

    private static function getPaymentDetails($cardDetails)
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($cardDetails['number']);
        $creditCard->setExpirationDate($cardDetails['expiration']);
        if (!empty($cardDetails['cvv'])) {
            $creditCard->setCardCode($cardDetails['cvv']);
        }

        $paymentDetails = new AnetAPI\PaymentType();
        $paymentDetails->setCreditCard($creditCard);

        return $paymentDetails;
    }

    /**
     * Convert pennies to dollars for Authorize.net
     *
     * @param integer $pennies
     *
     * @return float
     */
    private static function convertPenniesToDollars($pennies)
    {
        $money          = new Money($pennies, new Currency('USD'));
        $currencies     = new ISOCurrencies();
        $moneyFormatter = new DecimalMoneyFormatter($currencies);

        return $moneyFormatter->format($money);
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    private static function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @param string  $type
     * @param integer $pennies
     *
     * @return string
     */
    private static function createTransactionRequest($type, $pennies)
    {
        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType($type);
        $transactionRequest->setAmount(self::convertPenniesToDollars($pennies));

        return $transactionRequest;
    }

    /**
     * Execute a given transaction request
     *
     * @param TransactionRequestType $transactionRequest
     *
     * @return string
     */
    private static function buildAndExecuteRequest(TransactionRequestType $transactionRequest)
    {
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setRefId('ref' . time());
        $request->setTransactionRequest($transactionRequest);

        $requestor = new Requestor();
        $requestor->prepare($request);

        $controller = new AnetController\CreateTransactionController($request);

        return $controller->executeWithApiResponse($requestor->env);
    }
}
