<?php

namespace Laravel\CashierAuthorizeNet;

use App\Organization;
use Exception;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Carbon\Carbon;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Laravel\CashierAuthorizeNet\Requestor;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\constants as AnetConstants;
use net\authorize\api\controller as AnetController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    protected function getMerchantAuthentication()
    {
        $this->setAuthorizeAccount();

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
     * @throws Exception
     */
    public function initializeCustomerProfile()
    {
        $this->setAuthorizeAccount();

        $customerprofile = new AnetAPI\CustomerProfileType();
        $customerprofile->setMerchantCustomerId("M_" . $this->id);
        $customerprofile->setEmail($this->email);

        $requestor = new Requestor();
        $request   = $requestor->prepare(new AnetAPI\CreateCustomerProfileRequest());
        $request->setProfile($customerprofile);

        $controller = new AnetController\CreateCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response)) {
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();

            throw new \Exception($errorMessages[0]->getText(), $errorMessages[0]->getCode());
        }

        $this->authorize_id = $response->getCustomerProfileId();
        $this->save();
    }

    /**
     * Create an Authorize customer for the given user.
     *
     * @param array $options
     *
     * @return integer payment profile ID
     *
     * @throws Exception
     */
    public function addPaymentMethodToCustomer($cardDetails, $options = [])
    {
        if (!$this->authorize_id) {
            $this->initializeCustomerProfile();
        }

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
        $paymentprofilerequest->setCustomerProfileId($this->authorize_id);
        $paymentprofilerequest->setPaymentProfile($paymentprofile);
        //$paymentprofilerequest->setValidationMode("liveMode");

        // Create the controller and get the response
        $controller = new AnetController\CreateCustomerPaymentProfileController($paymentprofilerequest);
        $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
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
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        return $response->getCustomerPaymentProfileId();
    }

    public function getCustomerProfile()
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($this->authorize_id);
        $controller = new AnetController\GetCustomerProfileController($request);

        $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00040':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        $profileSelected = $response->getProfile();

        return $profileSelected;
    }

    public function getCustomerPaymentProfiles()
    {
        $profile         = $this->getCustomerProfile();
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

    public function deleteCustomerPaymentProfile($customerpaymentprofileid)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        // Use an existing payment profile ID for this Merchant name and Transaction key
        $request = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($this->authorize_id);
        $request->setCustomerPaymentProfileId($customerpaymentprofileid);
        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);
        $response   = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00040':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        return $this;
    }

    /**
     * Delete an Authorize.net Profile
     *
     * @return bool
     * @throws Exception
     */
    public function deleteAuthorizeProfile()
    {
        $requestor = new Requestor();
        $request   = $requestor->prepare((new AnetAPI\DeleteCustomerProfileRequest()));
        $request->setCustomerProfileId($this->authorize_id);

        $controller = new AnetController\DeleteCustomerProfileController($request);
        $response   = $controller->executeWithApiResponse($requestor->env);

        if (is_null($response) || $response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            throw new Exception("Response : " . $errorMessages[0]->getCode() . "  " . $errorMessages[0]->getText(), 1);
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
     * @throws Exception
     */
    public function charge($amount, $paymentProfileId, array $options = [])
    {
        if ($amount <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $this->setAuthorizeAccount();

        $options = array_merge([
            'currency' => self::preferredCurrency(),
        ], $options);


        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($this->authorize_id);
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
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00084':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        $tresponse = $response->getTransactionResponse();

        if (is_null($tresponse)) {
            throw new Exception('ERROR: NO TRANSACTION RESPONSE', 1);
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
                throw new Exception('Unknown response code: ' . $tresponse->getResponseCode());
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

        $this->setAuthorizeAccount();

        $paymentDetails = $this->getPaymentDetails($cardDetails);

        $transactionRequest = $this->createTransactionRequest("refundTransaction", $amount);
        $transactionRequest->setPayment($paymentDetails);

        $response = $this->buildAndExecuteRequest($transactionRequest);

        if (is_null($response)) {
            throw new Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00105':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        $tresponse = $response->getTransactionResponse();

        if (is_null($tresponse)) {
            throw new Exception('ERROR: NO TRANSACTION RESPONSE', 1);
        }

        if (is_null($tresponse->getErrors())) {
            throw new \Exception($tresponse->getErrors()[0]->getErrorText(),
                $tresponse->getErrors()[0]->getErrorCode());
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
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    private static function buildAndExecuteRequest($transactionRequest)
    {
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setRefId('ref' . time());
        $request->setTransactionRequest($transactionRequest);

        $requestor = new Requestor();
        $requestor->prepare($request);

        $controller = new AnetController\CreateTransactionController($request);

        /** @var AnetApiResponseType $response */
        return $controller->executeWithApiResponse($requestor->env);
    }

    /**
     *
     */
    private function setAuthorizeAccount()
    {
        $organization = $this->organization;

        $loader = new \Dotenv\Loader('notreal');
        $loader->setEnvironmentVariable('ADN_API_LOGIN_ID', $organization->adn_api_login_id);
        $loader->setEnvironmentVariable('ADN_TRANSACTION_KEY', $organization->adn_transaction_key);
        $loader->setEnvironmentVariable('ADN_SECRET_KEY', $organization->adn_secret_key);
    }

    /**
     *
     */
    private function clearAuthorizeAccount()
    {
        $loader = new \Dotenv\Loader('notreal');
        $loader->clearEnvironmentVariable('ADN_API_LOGIN_ID');
        $loader->clearEnvironmentVariable('ADN_TRANSACTION_KEY');
        $loader->clearEnvironmentVariable('ADN_SECRET_KEY');
    }
}
