<?php

namespace Laravel\CashierAuthorizeNet;

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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    /**
     * Determine if the entity has a Stripe customer ID.
     *
     * @return bool
     */
    public function hasAuthorizeId()
    {
        return ! is_null($this->authorize_id);
    }
    
    public function setAuthorizeAccount() {
        if(empty($this->site_id)) {
          throw new \Exception(' Customers need a Site');   
        }
    }
    
    protected function getMerchantAuthentication() {
       
        $this->setAuthorizeAccount();
        
        
        /* Create a merchantAuthenticationType object with authentication details
         retrieved from the constants file */
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));
        
        return $merchantAuthentication;
    }
    
    protected function getBillingObject($data=[]) {
         
        
        $billto = new AnetAPI\CustomerAddressType();
        if(!empty($data['first_name'])) {
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
     * Create a Authorize customer for the given user.
     *
     * @param  array $creditCardDetails
     * @return AuthorizeCustomer
     */
    public function createAsAuthorizeCustomer($paymentInformation, $options = [])
    {
        $this->setAuthorizeAccount();
        
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($paymentInformation['number']);
        $creditCard->setExpirationDate($paymentInformation['expiration']);
        if(!empty($paymentInformation['cvv'])) {
            $creditCard->setCardCode($paymentInformation['cvv']);
        }
        $paymentCreditCard = new AnetAPI\PaymentType();
        $paymentCreditCard->setCreditCard($creditCard);
        
        //TODO we most likely need to be able to pass in the billing
        $billto = $this->getBillingObject($options);
    
        $paymentprofile = new AnetAPI\CustomerPaymentProfileType();
        $paymentprofile->setCustomerType('individual');
        $paymentprofile->setBillTo($billto);
        $paymentprofile->setPayment($paymentCreditCard);
    
        $customerprofile = new AnetAPI\CustomerProfileType();
        $customerprofile->setMerchantCustomerId("M_".$this->id);
        $customerprofile->setEmail($this->email);
        $customerprofile->setPaymentProfiles([$paymentprofile]);
    
        $requestor = new Requestor();
        $request = $requestor->prepare(new AnetAPI\CreateCustomerProfileRequest());
        $request->setProfile($customerprofile);
        
        
        $controller = new AnetController\CreateCustomerProfileController($request);
    
        $response = $controller->executeWithApiResponse($requestor->env);
    
        if (($response != null) && ($response->getMessages()->getResultCode() === "Ok") ) {
            $this->authorize_id = $response->getCustomerProfileId();
            $this->authorize_payment_id = $response->getCustomerPaymentProfileIdList()[0];
            $this->save();
            
        } else {
    
            $errorMessages = $response->getMessages()->getMessage();
          
          //THIS THIS CUSTOMER ALREADY EXISTS FOR SOME REASON INSTEAD OF FAILING LETS RECOVER
          if(strpos(strtolower($errorMessages[0]->getText()), 'a duplicate record with id') !== false) {
             $this->authorize_id =  preg_replace('/\D+/', '', $errorMessages[0]->getText());
             $this->save(); 
             
            return $this->addPaymentMethodToCustomer($paymentInformation, $options);
          }
          
        }
    
        return $this->authorize_payment_id;
    }
         
    
        public function  addPaymentMethodToCustomer($paymentInformation, $options = []) {
        
            
            $merchantAuthentication = $this->getMerchantAuthentication();
            // Set the transaction's refId
            $refId = 'ref' . time();
            
         
            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber($paymentInformation['number']);
            $creditCard->setExpirationDate($paymentInformation['expiration']);
            if(!empty($paymentInformation['cvv'])) {
                $creditCard->setCardCode($paymentInformation['cvv']);
            }
            $paymentCreditCard = new AnetAPI\PaymentType();
            $paymentCreditCard->setCreditCard($creditCard);
            
            $billto = $this->getBillingObject($options);
            
            // Create a new Customer Payment Profile object
            $paymentprofile = new AnetAPI\CustomerPaymentProfileType();
            $paymentprofile->setCustomerType('individual');
            $paymentprofile->setBillTo($billto);
            $paymentprofile->setPayment($paymentCreditCard);
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
            $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
            
            if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") ) {
                return $response->getCustomerPaymentProfileId();
            } else {
                $errorMessages = $response->getMessages()->getMessage();
                throw new \Exception($errorMessages[0]->getText());            
            }
            return $response;
        
        }

      
    public function getCustomerProfile() {
        
        $merchantAuthentication = $this->getMerchantAuthentication();
        // Set the transaction's refId
        $refId = 'ref' . time();
        
        $request = new AnetAPI\GetCustomerProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($this->authorize_id);
        $controller = new AnetController\GetCustomerProfileController($request);
        
        $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") )
        {
            $profileSelected = $response->getProfile();
            return $profileSelected;
        }
        else
        {
            throw new \Exception( $response->getMessages()->getMessage());
              }
        return $response;
    }
    
    public function getCustomerPaymentProfiles() {
    
        $profile = $this->getCustomerProfile();
        
        $payments = $profile->getPaymentProfiles();
       
        return $payments;
    }
    
    public function getCustomerPaymentMethodsClean() {
    
        $paymentprofiles = $this->getCustomerPaymentProfiles();
        
        $paymentMethods = [];
        foreach($paymentprofiles as $profile) {
            $paymentMethod = [];
            $paymentMethod['payment_profile_id'] = $profile->getCustomerPaymentProfileId();
            $card = $profile->getPayment()->getCreditCard();
            $paymentMethod['cardNumber'] = $card->getCardNumber();
            $paymentMethod['expirationDate'] = $card->getExpirationDate();
            $paymentMethod['type'] = $card->getCardType();
            $paymentMethods[] = $paymentMethod;
        }
        
        return $paymentMethods;
    }
    
   
    
    
    public function deleteCustomerPaymentProfile($customerpaymentprofileid) {
        $merchantAuthentication = $this->getMerchantAuthentication();
        // Set the transaction's refId
        $refId = 'ref' . time();
        
        // Use an existing payment profile ID for this Merchant name and Transaction key
         
        $request = new AnetAPI\DeleteCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setCustomerProfileId($this->authorize_id);
        $request->setCustomerPaymentProfileId($customerpaymentprofileid);
        $controller = new AnetController\DeleteCustomerPaymentProfileController($request);
        $response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX);
        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") )
        {
            return $this;
        }
        else
        {
            $errorMessages = $response->getMessages()->getMessage();
            throw new \Exception($errorMessages[0]->getText(),$errorMessages[0]->getCode());
        }
        
    }
        
    /**
     * Delete an Authorize.net Profile
     *
     * @return
     */
    public function deleteAuthorizeProfile()
    {
        $requestor = new Requestor();
        $request = $requestor->prepare((new AnetAPI\DeleteCustomerProfileRequest()));
        $request->setCustomerProfileId($this->authorize_id);
    
        $controller = new AnetController\DeleteCustomerProfileController($request);
        $response = $controller->executeWithApiResponse($requestor->env);
    
        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok") ) {
            return true;
        } else {
            $errorMessages = $response->getMessages()->getMessage();
            throw new Exception("Response : " . $errorMessages[0]->getCode() . "  " .$errorMessages[0]->getText(), 1);
        }
    
        return false;
    }
   
    
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  int  $profile_id
     * @param  array  $options

     */
    public function charge($amount, $profile_id,  array $options = [])
    {
        $this->setAuthorizeAccount();
        
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);
        
        $requestor = new Requestor();

        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($this->authorize_id);
        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($profile_id);
        $profileToCharge->setPaymentProfile($paymentProfile);

        
        
        
        $order = new AnetAPI\OrderType;
        $order->setDescription($options['description']);

        
        //convert pennies to dollars for authorize
        
        $money = new Money($amount, new Currency('USD'));
        $currencies = new ISOCurrencies();
        
        $moneyFormatter = new DecimalMoneyFormatter($currencies);
        
         
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($moneyFormatter->format($money));
        
        $transactionRequestType->setCurrencyCode($options['currency']);
        $transactionRequestType->setOrder($order);
        $transactionRequestType->setProfile($profileToCharge);

        $request = $requestor->prepare((new AnetAPI\CreateTransactionRequest()));
        $request->setTransactionRequest($transactionRequestType);
        $controller = new AnetController\CreateTransactionController($request);
        $response = $controller->executeWithApiResponse($requestor->env);

        
        if ($response != null) {
            $tresponse = $response->getTransactionResponse();
            if (($tresponse != null) && ($tresponse->getResponseCode() == '1') ) {
                return [
                    'authCode' => $tresponse->getAuthCode(),
                    'transId' => $tresponse->getTransId(),
                ];
            } else if (($tresponse != null) && ($tresponse->getResponseCode() == "2") ) {
                return false;
            } else if (($tresponse != null) && ($tresponse->getResponseCode() == "4") ) {
                throw new Exception("ERROR: HELD FOR REVIEW", 1);
            }
        } else {
            throw new Exception("ERROR: NO RESPONSE", 1);
        }

        return false;
    }

    /**
     * Determines if the customer currently has a card on file.
     *
     * @return bool
     */
    public function hasCardOnFile()
    {
        return (bool) count($this->getCustomerPaymentProfiles());
    }

    /**
     * Invoice the customer for the given amount.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return bool
     *
     * @throws \Stripe\Error\Card
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        if (! $this->authorize_id) {
            throw new InvalidArgumentException('User is not a customer. See the createAsAuthorizeCustomer method.');
        }

        $options = array_merge([
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        return $this->charge($amount, $options);
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\CashierAuthorizeNet\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->authorize_plan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
               $subscription->authorize_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })
        ->first(function ($key, $value) use ($subscription) {
            return $value->name === $subscription;
        });
    }

    /**
     * Get all of the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'user_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the entity's upcoming invoice.
     *
     * @return \Laravel\Cashier\Invoice|null
     */
    public function upcomingInvoice()
    {
        $subscription = $this->subscriptions()->first();
        $startDate = $subscription->created_at;
        $now = Carbon::now();
        $authorizePlan = $subscription->authorize_plan;
        $config = Config::get('cashier-authorize');

        $thisMonthsBillingDate = Carbon::createFromDate($now->year, $now->month, $startDate->day);

        if ($thisMonthsBillingDate->lte($now)) {
            $billingDate = $thisMonthsBillingDate;
        } else {
            $billingDate = $thisMonthsBillingDate->addMonths(1);
        }

        $invoice = new Invoice($this, [
            'date' => $billingDate->timestamp,
            'subscription' => $subscription,
            'tax_percent' => $this->taxPercentage(),
            'tax' => floatval($config[$authorizePlan]['amount']) * floatval('0.'.$this->taxPercentage())
        ]);

        return $invoice;
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice|null
     */
    public function findInvoice($invoiceId)
    {
        $requestor = new Requestor();
        $request = $requestor->prepare((new AnetAPI\GetTransactionDetailsRequest()));
        $request->setTransId($invoiceId);

        $controller = new AnetController\GetTransactionDetailsController($request);

        $response = $controller->executeWithApiResponse($requestor->env);

        $invoice = [];

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
            $invoice = [
                'id' => $response->getTransaction()->getTransId(),
                'amount' => $response->getTransaction()->getAuthAmount(),
                'status' => $response->getTransaction()->getTransactionStatus(),
                'response' => $response
            ];
        } else {
            $errorMessages = $response->getMessages()->getMessage();
            throw new Exception("Response : " . $errorMessages[0]->getCode() . "  " .$errorMessages[0]->getText(), 1);
        }

        return $invoice;
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            return false;
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array   $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        if (! $this->findInvoiceOrFail($id)) {
            $invoices = $this->invoices();
            return $invoices[$id]->download($data, $storagePath);
        }

        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a subcription from Authorize
     *
     * @param  string $subscriptionId
     * @return array
     */
    public function getSubscriptionFromAuthorize($subscriptionId)
    {
        $requestor = new Requestor();
        $request = $requestor->prepare((new AnetAPI\ARBGetSubscriptionRequest()));
        $request->setSubscriptionId($subscriptionId);
        $controller = new AnetController\ARBGetSubscriptionController($request);
        $response = $controller->executeWithApiResponse($requestor->env);
        $subscription = [];

        if ($response != null) {
            if ($response->getMessages()->getResultCode() == "Ok") {
                $subscription = [
                    'name' => $response->getSubscription()->getName(),
                    'amount' => $response->getSubscription()->getAmount(),
                    'status' => $response->getSubscription()->getStatus(),
                    'description' => $response->getSubscription()->getProfile()->getDescription(),
                    'customer' => $response->getSubscription()->getProfile()->getCustomerProfileId(),
                ];
            } else {
                $errorMessages = $response->getMessages()->getMessage();
                throw new Exception("Response : " . $errorMessages[0]->getCode() . "  " .$errorMessages[0]->getText(), 1);
            }
        } else {
            throw new Exception("Null response error", 1);
        }

        return $subscription;
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  string  $plan
     * @return \Illuminate\Support\Collection
     */
    public function invoices($plan)
    {
        $subscription = $this->subscriptions($plan)->first();
        $startDate = $subscription->created_at;
        $authorizeId = $subscription->authorize_id;
        $authorizePlan = $subscription->authorize_plan;
        $config = Config::get('cashier-authorize');
        $endDate = Carbon::now();
        $difference = $startDate->diffInMonths($endDate);
        $subscription = $this->getSubscriptionFromAuthorize($authorizeId);

        $invoices = [];

        if ($difference >= 1) {
            foreach (range(1, $difference) as $invoiceNumber) {
                $date = $startDate->addMonths($invoiceNumber);
                $invoices[] = new Invoice($this, [
                    'date' => $date->timestamp,
                    'subscription' => $subscription,
                    'tax_percent' => $this->taxPercentage(),
                    'tax' => floatval($config[$authorizePlan]['amount']) * floatval('0.'.$this->taxPercentage())
                ]);
            }
        }

        return new Collection($invoices);
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param  array|string  $plans
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->authorize_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null($this->subscriptions->first(function ($key, $value) use ($plan) {
            return $value->authorize_plan === $plan && $value->valid();
        }));
    }

   

   

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    public function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Detect the brand cause Authorize wont give that to us
     *
     * @param  string $card Card number
     * @return string
     */
    public function cardBrandDetector($card)
    {
        $brand = 'Unknown';
        $number = preg_replace('/[^\d]/', '', $card);

        if (preg_match('/^3[47][0-9]{13}$/', $number)) {
            $brand = 'American Express';
        } elseif (preg_match('/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/', $number)) {
            $brand = 'Diners Club';
        } elseif (preg_match('/^6(?:011|5[0-9][0-9])[0-9]{12}$/', $number)) {
            $brand = 'Discover';
        } elseif (preg_match('/^(?:2131|1800|35\d{3})\d{11}$/', $number)) {
            $brand = 'JCB';
        } elseif (preg_match('/^5[1-5][0-9]{14}$/', $number)) {
            $brand = 'MasterCard';
        } elseif (preg_match('/^4[0-9]{12}(?:[0-9]{3})?$/', $number)) {
            $brand = 'Visa';
        }

        return $brand;
    }
    
    /*
     * If you are submitting a refund against a previous customer profile transaction, the following guidelines apply:

    Include customerProfileId, customerPaymentProfileId, and transId.
    
     */
    public function refundPreviousTransaction($transId, $amount, $customerPaymentProfileId ) {
        
        /* Create a merchantAuthenticationType object with authentication details
         retrieved from the constants file */
        $merchantAuthentication = $this->getMerchantAuthentication();
        
        // Set the transaction's refId
        $refId = 'ref' . time();

        $transaction = new AuthorizeNetTransaction;
    	$transaction->amount = $amount;
    	$transaction->customerProfileId = $this->$this->authorize_id;
    	$transaction->customerPaymentProfileId = $customerPaymentProfileId;
    	$transaction->transId = $transId; // original transaction ID
    
    	
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest( $transaction);
        
        
        $response = $request->createCustomerProfileTransaction("Refund", $transaction);
        $transactionResponse = $response->getTransactionResponse();
        
        $transactionId = $transactionResponse->transaction_id;
        
       
        
        return $response;
        
    }
    
    /*
     * If you are submitting a refund for a non-profile transaction, the following guidelines apply:

You must include transId, creditCardNumberMasked (or bankRoutingNumberMasked and bankAccountNumberMasked).

     */
    public function refundNonProfileTransaction($transId, $creditCardNumberMasked ) {
    
    }
    
    /*
     * You must be enrolled in Expanded Credit Capabilities (ECC). For more information about ECC, go to http://www.authorize.net/files/ecc.pdf.
     * You must include customerProfileId and customerPaymentProfileId.
     * 
     */
    public function refundUnlinkedProfileTransaction($transId, $creditCardNumberMasked ) {
    
    }
}
