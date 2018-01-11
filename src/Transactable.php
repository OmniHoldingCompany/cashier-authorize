<?php

namespace Laravel\CashierAuthorizeNet;

trait Transactable
{
    public function getTransactionApi()
    {
        return new TransactionApi();
    }

    /**
     * Make a "one off" charge on the customer for the transaction amount.
     *
     * @param integer $pennies
     * @param integer $paymentProfileId
     *
     * @return array
     * @throws \Exception
     */
    public function charge($pennies, $paymentProfileId)
    {
        if ($pennies <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $transactionApi = $this->getTransactionApi();

        $transactionDetails = $transactionApi->charge($pennies, $this->user->authorize_id, $paymentProfileId);

        return $transactionDetails;
    }

    /**
     * Return money to original credit card.
     *
     * @param  integer $pennies Amount to be refunded, in cents
     *
     * @return integer
     * @throws \Exception
     */
    public function refund($pennies)
    {
        if ($pennies <= 0) {
            throw new \Exception('Refund amount must be greater than 0');
        }

        $payment = $this->payment;

        $transactionApi = $this->getTransactionApi();

        return $transactionApi->refundTransaction($pennies, $payment->payment_trans_id, $payment->last_four);
    }
}
