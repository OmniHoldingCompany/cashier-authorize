<?php

namespace Laravel\CashierAuthorizeNet;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use net\authorize\api\constants\ANetEnvironment as ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller\CreateTransactionController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

trait Transactable
{

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  integer $amount              Amount to be charged, in cents
     * @param  integer $paymentProfileId
     * @param  array   $options
     *
     * @return array
     * @throws \Exception
     */
    public function charge($amount, $paymentProfileId, array $options = [])
    {
        $user = $this->user;

        if ($amount <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $options = array_merge([
            'currency' => self::preferredCurrency(),
        ], $options);

        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($paymentProfileId);

        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($user->getAuthorizeId());
        $profileToCharge->setPaymentProfile($paymentProfile);

        $order = new AnetAPI\OrderType;
        $order->setDescription($options['description']);

        $transactionRequest = self::createTransactionRequest("authCaptureTransaction", $amount);

        $transactionRequest->setCurrencyCode($options['currency']);
        $transactionRequest->setOrder($order);
        $transactionRequest->setProfile($profileToCharge);

        $tresponse = $this->buildAndExecuteRequest($transactionRequest);

        return [
            'authCode' => $tresponse->getAuthCode(),
            'transId'  => $tresponse->getTransId(),
        ];
    }

    /**
     * Return money to a credit card.
     *
     * @param  integer $amount      Amount to be refunded, in dollars
     * @param  array   $cardDetails Credit cards details (leave null for original card)
     *
     * @return integer ADN Transaction ID
     * @throws \Exception
     */
    public function refund($amount, $cardDetails = null)
    {
        if ($amount <= 0) {
            throw new \Exception('Refund amount must be greater than 0');
        }

        /** @var AnetAPI\TransactionRequestType $transactionRequest */
        $transactionRequest = self::createTransactionRequest("refundTransaction", $amount);

        if (!is_null($cardDetails)) {
            $paymentDetails = self::getPaymentDetails($cardDetails);
        } else {
            $payment         = $this->payments()->first();
            $paymentDetails  = self::getPaymentDetails(['number' => $payment->last_four]);
            $transactionRequest->setRefTransId($payment->payment_trans_id);
        }

        $transactionRequest->setPayment($paymentDetails);

        $tresponse = $this->buildAndExecuteRequest($transactionRequest);

        return $tresponse->getTransId();
    }
}
