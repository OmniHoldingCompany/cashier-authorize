<?php

namespace Laravel\CashierAuthorizeNet;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
     * @return AuthorizeTransaction
     * @throws \Exception
     */
    public function charge($pennies, $paymentProfileId)
    {
        if ($pennies <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $transactionApi = $this->getTransactionApi();

        $transactionDetails = $transactionApi->charge($pennies, $this->user->authorize_id, $paymentProfileId);

        $authorizeTransaction = $this->authorizeTransactions()->create([
            'organization_id'        => $this->organization_id,
            'adn_authorization_code' => $transactionDetails['authCode'],
            'adn_transaction_id'     => $transactionDetails['transId'],
            'last_four'              => $transactionDetails['lastFour'],
            'amount'                 => $transactionApi::convertDollarsToPennies($transactionDetails['amount']),
            'type'                   => 'payment',
        ]);

        return $authorizeTransaction;
    }

    /**
     * Make a "card-present" charge for the transaction amount.
     *
     * @param integer $pennies
     * @param string  $trackDetails
     *
     * @return AuthorizeTransaction
     * @throws \Exception
     */
    public function chargeCard($pennies, $trackDetails)
    {
        if ($pennies <= 0) {
            throw new \Exception('Charge amount must be greater than 0');
        }

        $transactionApi = $this->getTransactionApi();

        $transactionDetails = $transactionApi->chargeTrack($pennies, $trackDetails);

        $amount = $transactionApi::convertDollarsToPennies($transactionDetails['amount']);

        $authorizeTransaction = $this->authorizeTransactions()->create([
            'organization_id'        => $this->organization_id,
            'adn_authorization_code' => $transactionDetails['authCode'],
            'adn_transaction_id'     => $transactionDetails['transId'],
            'last_four'              => $transactionDetails['lastFour'],
            'amount'                 => $transactionApi::convertDollarsToPennies($transactionDetails['amount']),
            'type'                   => 'payment',
        ]);

        return $authorizeTransaction;
    }

    /**
     * Return money to original credit card.
     *
     * @param  integer $pennies Amount to be refunded, in cents
     *
     * @return AuthorizeTransaction
     * @throws \Exception
     */
    public function refund($pennies)
    {
        if ($pennies <= 0) {
            throw new \Exception('Refund amount must be greater than 0');
        }


        $transactionDetails = $this->getDetails();

        if (!in_array($transactionDetails['status'], ['settledSuccessfully'])) {
            throw new BadRequestHttpException('Transaction must be settled before it can be refunded.  Void transaction instead.');
        }

        $payment = $this->last_payment;

        $transactionApi = $this->getTransactionApi();

        $transactionDetails = $transactionApi->refundTransaction($pennies, $payment->payment_trans_id, $payment->last_four);

        $authorizeTransaction = $this->authorizeTransactions()->create([
            'organization_id'        => $this->organization_id,
            'adn_authorization_code' => $transactionDetails['authCode'],
            'adn_transaction_id'     => $transactionDetails['transId'],
            'last_four'              => $transactionDetails['lastFour'],
            'amount'                 => $pennies,
            'type'                   => $transactionDetails['type'],
        ]);

        return $authorizeTransaction;
    }

    /**
     * Void transaction if pending.
     *
     * @return integer
     * @throws \Exception
     */
    public function void()
    {
        $transactionDetails = $this->getDetails();

        if (!in_array($transactionDetails['status'], ['authorizedPendingCapture', 'capturedPendingSettlement', 'FDSPendingReview'])) {
            throw new BadRequestHttpException('Transaction must be pending settlement in order to be voided.  Refund transaction instead.');
        }

        $payment = $this->last_payment;

        $transactionApi = $this->getTransactionApi();

        $transactionApi->voidTransaction($payment->adn_transaction_id);

        $payment->status = 'voided';
        $payment->save();

        $this->status = 'void';
        $this->save();

        return true;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getDetails()
    {
        $transactionApi = $this->getTransactionApi();

        $payment = $this->last_payment;
        $details = $transactionApi->getTransactionDetails($payment->adn_transaction_id);

        return [
            'status'            => $details->getTransactionStatus(),
            'type'              => $details->getTransactionType(),
            'fds_filter_action' => $details->getFDSFilterAction(),
            'authorize_amount'  => $transactionApi::convertDollarsToPennies($details->getAuthAmount()),
            'settle_amount'     => $transactionApi::convertDollarsToPennies($details->getSettleAmount()),
            'submitted_at'      => $details->getSubmitTimeUTC(),
            //'payment_profile_id' => $details->getPayment(),
        ];
    }
}
