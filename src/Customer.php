<?php

namespace Laravel\CashierAuthorizeNet;

use net\authorize\api\contract\v1 as AnetAPI;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait Customer
{
    public function getCustomerApi()
    {
        return new CustomerApi();
    }

    /*********************
     * CUSTOMER PROFILES *
     *********************/

    /**
     * Create an Authorize customer profile for this user.
     *
     * @throws \Exception
     */
    public function initializeCustomerProfile()
    {
        $authorizeCustomerProfile = $this->getCustomerApi();
        $authorizeCustomerId      = $authorizeCustomerProfile->createCustomerProfile([
            'email'                => $this->email,
            'merchant_customer_id' => $this->id,
        ]);

        $this->authorize_id          = $authorizeCustomerId;
        $this->authorize_merchant_id = $this->id;
        $this->save();

        return $authorizeCustomerId;
    }

    /**
     * Get this users customer profile.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfile()
    {
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->getCustomerProfile(['customer_profile_id' => $this->getAuthorizeId()]);
    }

    /**
     * Get this users customer id.
     *
     * @return integer
     * @throws \Exception
     */
    public function getAuthorizeId()
    {
        if (is_null($this->authorize_id)) {
            try {
                /** @var AnetAPI\CustomerProfileMaskedType $authorizeCustomerProfile */
                $authorizeCustomerProfile = $this->getCustomerProfileByMerchantId();

                $this->authorize_id = $authorizeCustomerProfile->getCustomerProfileId();
                $this->save();
            } catch (BadRequestHttpException $e) {
                $this->initializeCustomerProfile();
            }
        }

        return $this->authorize_id;
    }

    /**
     * Get this users customer profile by searching for their merchant id.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfileByMerchantId()
    {
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->getCustomerProfile([
            'merchant_customer_id' => $this->getAuthorizeMerchantId(),
            'email'                => $this->email,
        ]);
    }

    /**
     * Get this users merchant id.
     *
     * @return integer
     * @throws \Exception
     */
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

    /**
     * Get this users customer profile by searching for their email address.
     *
     * @return AnetAPI\CustomerProfileMaskedType
     * @throws \Exception
     */
    public function getCustomerProfileByEmail()
    {
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->getCustomerProfile(['email' => $this->email]);
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
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->updateCustomerProfile($this->getAuthorizeId(), $profileDetails);
    }

    /**
     * Delete this users customer profile.
     *
     * @throws \Exception
     */
    public function deleteCustomerProfile()
    {
        $authorizeCustomerProfile = $this->getCustomerApi();

        if (!$authorizeCustomerProfile->deleteCustomerProfile($this->getAuthorizeId())) {
            throw new \Exception('Failed to delete authorize profile');
        }

        $this->authorize_id          = null;
        $this->authorize_merchant_id = null;
        $this->authorize_payment_id  = null;
        $this->save();
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
        $authorizeCustomerProfile = $this->getCustomerApi();

        $paymentType = $authorizeCustomerProfile::getCreditCardPaymentType($cardDetails);

        $billTo = $authorizeCustomerProfile::getBillingObject([
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'address_1'  => $this->address_1,
            'city'       => $this->city,
            'state'      => $this->state,
            'zip'        => $this->zip,
            'country'    => $this->country,
        ]);

        $paymentProfile = $authorizeCustomerProfile::buildPaymentProfile($paymentType, $billTo);

        $paymentProfileId = $authorizeCustomerProfile->addPaymentProfile($this->getAuthorizeId(), $paymentProfile);

        if ($default) {
            $this->authorize_payment_id = $paymentProfileId;
            $this->save;
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
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->getPaymentProfile($this->getAuthorizeId(), $paymentProfileId);
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

            if (isset($card)) {
                $paymentMethods['credit_cards'][] = [
                    'id'         => $profile->getCustomerPaymentProfileId(),
                    'number'     => $card->getCardNumber(),
                    'expiration' => $card->getExpirationDate(),
                    'type'       => $card->getCardType(),
                ];
            } elseif (isset($bankAccount)) {
                $paymentMethods['bank_accounts'][] = [
                    'id'              => $profile->getCustomerPaymentProfileId(),
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
     * @param string $customerPaymentProfileId
     *
     * @return bool
     * @throws \Exception
     */
    public function deleteCustomerPaymentProfile($customerPaymentProfileId)
    {
        $authorizeCustomerProfile = $this->getCustomerApi();

        return $authorizeCustomerProfile->deletePaymentProfile($this->getAuthorizeId(), $customerPaymentProfileId);
    }
}
