<?php

namespace Laravel\CashierAuthorizeNet;

use App\AuthorizeTransaction;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

trait Transactable
{
    public function getTransactionApi()
    {
        return new TransactionApi();
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
            throw new ConflictHttpException('Transaction must be settled before it can be refunded.  Void transaction instead.');
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
            throw new ConflictHttpException('Transaction must be pending settlement in order to be voided.  Refund transaction instead.');
        }

        /** @var AuthorizeTransaction $payment */
        $payment = $this->last_payment;

        $transactionApi = $this->getTransactionApi();
        $transactionApi->voidTransaction($payment->adn_transaction_id);

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
        ];
    }
}
