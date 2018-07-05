<?php

namespace Laravel\CashierAuthorizeNet;

use App\Organization;
use App\User;
use net\authorize\api\contract\v1 as AnetAPI;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class authorizeCustomerManager
 * @package App\Services
 */
class AuthorizeCustomerManager
{
    /** @var User $customer */
    private $customer;

    /** @var CustomerApi $customerApi */
    private $customerApi;

    /**
     * @param Organization $merchant
     */
    public function setMerchant(Organization $merchant)
    {
        $loader = new \Dotenv\Loader('notreal');
        $loader->setEnvironmentVariable('ADN_API_LOGIN_ID', $merchant->adn_api_login_id);
        $loader->setEnvironmentVariable('ADN_TRANSACTION_KEY', $merchant->adn_transaction_key);
        $loader->setEnvironmentVariable('ADN_SECRET_KEY', $merchant->adn_secret_key);

        $this->customerApi = resolve(CustomerApi::class);
        $this->customerApi->__construct();  // Laravel wont call __construct on its own for some reason
    }

    /*********************
     * CUSTOMER PROFILES *
     *********************/

     /**
     * @param User $customer
     */
    public function setCustomer(User $customer)
    {
        $this->customer = $customer;
    }

    /**
     * Create an Authorize customer profile for this user.
     *
     * @param User $customer
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function initializeCustomerProfile(User $customer)
    {
        $authorizeCustomerId = $this->customerApi->createCustomerProfile([
            'email'                => $customer->email,
            'merchant_customer_id' => $customer->id,
        ]);

        $customer->authorize_id          = $authorizeCustomerId;
        $customer->authorize_merchant_id = $customer->id;
        $customer->save();

        $this->setCustomer($customer);

        return $this->getCustomerProfile();
    }

    /**
     * Get this users customer profile.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfile()
    {
        return $this->customerApi->getCustomerProfile(['customer_profile_id' => $this->getAuthorizeId()]);
    }

    /**
     * Get this users customer id.
     *
     * @return integer
     * @throws \Exception
     */
    public function getAuthorizeId()
    {
        if (is_null($this->customer->authorize_id)) {
            /** @var AnetAPI\CustomerProfileMaskedType $authorizeCustomerProfile */
            $authorizeCustomerProfile = $this->getCustomerProfileByMerchantId();

            $this->customer->authorize_id = $authorizeCustomerProfile->getCustomerProfileId();
            $this->customer->save();
        }

        return $this->customer->authorize_id;
    }

    /**
     * Get this users customer profile by searching for their merchant id.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfileByMerchantId()
    {
        try {
            $authorizeCustomerProfile = $this->customerApi->getCustomerProfile([
                'merchant_customer_id' => $this->getAuthorizeMerchantId()
            ]);
        } catch (NotFoundHttpException $e) {
            $authorizeCustomerProfile = $this->getCustomerProfileByEmail();
        }

        return $authorizeCustomerProfile;
    }

    /**
     * Get this users merchant id.
     *
     * @return integer
     * @throws \Exception
     */
    public function getAuthorizeMerchantId()
    {
        if (is_null($this->customer->authorize_merchant_id)) {
            $authorizeCustomerProfile = $this->getCustomerProfileByEmail();

            $this->customer->authorize_merchant_id = $authorizeCustomerProfile->getMerchantCustomerId();
            $this->customer->authorize_id          = $authorizeCustomerProfile->getCustomerProfileId();
            $this->customer->save();
        }

        return $this->customer->authorize_merchant_id;
    }

    /**
     * Get this users customer profile by searching for their email address.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfileByEmail()
    {
        try {
            $authorizeCustomerProfile = $this->customerApi->getCustomerProfile([
                'email' => $this->customer->email,
            ]);
        } catch (NotFoundHttpException $e) {
            $authorizeCustomerProfile = $this->initializeCustomerProfile($this->customer);
        }

        return $authorizeCustomerProfile;
    }

    /**
     * Update this users customer profile.
     *
     * @param array $profileDetails
     *
     * @return mixed
     * @throws \Exception
     */
    public function updateCustomerProfile($profileDetails)
    {
        return $this->customerApi->updateCustomerProfile($this->getAuthorizeId(), $profileDetails);
    }

    /**
     * Delete this users customer profile.
     *
     * @throws \Exception
     */
    public function deleteCustomerProfile()
    {
        if (!$this->customerApi->deleteCustomerProfile($this->getAuthorizeId())) {
            throw new \Exception('Failed to delete authorize profile');
        }

        $this->customer->authorize_id          = null;
        $this->customer->authorize_merchant_id = null;
        $this->customer->authorize_payment_id  = null;
        $this->customer->save();
    }


    /********************
     * PAYMENT PROFILES *
     ********************/

    /**
     * Add a new credit card to this users profile.
     *
     * @param array   $cardDetails
     * @param boolean $default
     *
     * @return string
     * @throws \Exception
     * @throws BadRequestHttpException
     */
    public function addCreditCard($cardDetails, $default = false)
    {
        $paymentType = $this->customerApi::getCreditCardPaymentType($cardDetails);

        $billTo = $this->customerApi::getBillingObject($cardDetails);

        $paymentProfile = $this->customerApi::buildPaymentProfile($paymentType, $billTo);

        $paymentProfileId = $this->customerApi->addPaymentProfile($this->getAuthorizeId(), $paymentProfile);

        if ($default || !$this->customer->authorize_payment_id) {
            $this->customer->authorize_payment_id = $paymentProfileId;
            $this->customer->save();
        }

        return $paymentProfileId;
    }

    /**
     * Retrieve a specific payment profile for this user.
     *
     * @param integer $paymentProfileId
     *
     * @return AnetAPI\CustomerPaymentProfileMaskedType
     * @throws \Exception
     */
    public function getPaymentProfile($paymentProfileId)
    {
        return $this->customerApi->getPaymentProfile($this->getAuthorizeId(), $paymentProfileId);
    }

    /**
     * List all of this users payment methods.
     *
     * @return array
     * @throws \Exception
     */
    private function getCustomerPaymentProfiles()
    {
        $customerProfile = $this->getCustomerProfile();

        /** @var AnetAPI\CustomerPaymentProfileMaskedType[] $paymentProfiles */
        $paymentProfiles = $customerProfile->getPaymentProfiles();

        $paymentMethods = [
            'bank_accounts' => [],
            'credit_cards'  => [],
        ];

        foreach ($paymentProfiles as $profile) {
            $card        = $profile->getPayment()->getCreditCard();
            $bankAccount = $profile->getPayment()->getBankAccount();

            $profileId = $profile->getCustomerPaymentProfileId();

            if (isset($card)) {
                $paymentMethods['credit_cards'][] = [
                    'id'         => $profileId,
                    'number'     => $card->getCardNumber(),
                    'expiration' => $card->getExpirationDate(),
                    'type'       => $card->getCardType(),
                    'primary'    => $this->customer->authorize_payment_id == $profileId,
                ];
            } elseif (isset($bankAccount)) {
                $paymentMethods['bank_accounts'][] = [
                    'id'              => $profileId,
                    'account_number'  => $bankAccount->getAccountNumber(),
                    'account_type'    => $bankAccount->getAccountType(),
                    'bank_name'       => $bankAccount->getBankName(),
                    'echeck_type'     => $bankAccount->getEcheckType(),
                    'name_on_account' => $bankAccount->getNameOnAccount(),
                    'routing_number'  => $bankAccount->getRoutingNumber(),
                ];
            }
        }

        return $paymentMethods;
    }

    /**
     * List all of this users credit cards.
     *
     * @return array
     * @throws \Exception
     */
    public function getCustomerCreditCards()
    {
        $paymentProfiles = $this->getCustomerPaymentProfiles();

        return $paymentProfiles['credit_cards'];
    }

    /**
     * List all of this users bank accounts.
     *
     * @return array
     * @throws \Exception
     */
    public function getCustomerBankAccounts()
    {
        $paymentProfiles = $this->getCustomerPaymentProfiles();

        return $paymentProfiles['bank_accounts'];
    }

    /**
     * Delete a specific payment profile from this user.
     *
     * @param int $customerPaymentProfileId
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteCustomerPaymentProfile($customerPaymentProfileId)
    {
        if ($customerPaymentProfileId == $this->customer->authorize_payment_id) {
            throw new ConflictHttpException('Primary payment can not be removed.');
        }

        return $this->customerApi->deletePaymentProfile($this->getAuthorizeId(), $customerPaymentProfileId);
    }

    /**
     * Delete all customer data from merchant account and update user database to match.
     *
     * @throws \Exception
     */
    public function truncateCustomers()
    {
        $customerProfileIds = $this->customerApi->listCustomerProfileIds();

        foreach ($customerProfileIds as $customerProfileId) {
            $this->deleteCustomerPaymentProfile($customerProfileId);
        }

        $this->users()->update([
            'authorize_merchant_id' => null,
            'authorize_id'          => null,
            'authorize_payment_id'  => null,
        ]);
    }
}