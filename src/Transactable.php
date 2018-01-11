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
     * @param integer $paymentProfileId
     *
     * @return boolean
     * @throws \Exception
     */
    public function charge($paymentProfileId)
    {
        $amount = $this->amount_due;

        if ($amount <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $transactionApi = $this->getTransactionApi();

        $transactionDetails = $transactionApi->charge($amount, $this->user->authorize_id, $paymentProfileId);

        $this->payments()->save(new Payment([
            'organization_id'   => $this->organization_id,
            'payment_auth_code' => $transactionDetails['authCode'],
            'payment_trans_id'  => $transactionDetails['transId'],
            'amount'            => $amount,
        ]));

        $this->payment_applied += $amount;
        $this->amount_due      -= $amount;
        $this->save();

        return true;
    }

    /**
     * Return money to original credit card.
     *
     * @param  integer $pennies Amount to be refunded, in cents
     *
     * @return integer
     * @throws \Exception
     */
    public function refund($pennies = null)
    {
        if (is_null($pennies)) {
            $pennies = $this->payment_applied - $this->refund;
        }

        if ($pennies <= 0) {
            throw new \Exception('Refund amount must be greater than 0');
        }

        $payment = $this->payment;

        $transactionApi = $this->getTransactionApi();
        $transactionId  = $transactionApi->refundTransaction($pennies, $payment->payment_trans_id, $payment->last_four);

        Refund::create([
            'transaction_id'  => $this->id,
            'amount'          => $pennies,
            'refund_trans_id' => $transactionId,
        ]);

        $this->refund += $pennies;
        $this->save;

        return true;
    }
}
