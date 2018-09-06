<?php

namespace Laravel\CashierAuthorizeNet;

use App\Events\OrderPlaced;
use App\Exceptions\CheckoutRestrictionException;
use App\Exceptions\PaymentException;
use App\StoreCredit;
use App\Transaction;
use App\TransactionItem;
use App\User;
use Illuminate\Support\Facades\DB;
use Laravel\CashierAuthorizeNet\Models\AuthorizeTransaction;
use Laravel\CashierAuthorizeNet\Jobs\SyncTransaction;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Class TransactionProcessor
 * @package App\Services
 */
class TransactionProcessor
{
    /** @var TransactionApi $transactionApi */
    private $transactionApi;

    /** @var Transaction $transaction */
    private $transaction;

    /** @var string|array $paymentData */
    private $paymentData;

    /** @var boolean $storeProfile */
    public $storeProfile = false;

    /**
     * @param Transaction $transaction
     *
     * @return void
     */
    public function setTransaction(Transaction $transaction)
    {
        $this->transaction = $transaction;

        $organization = $transaction->organization;

        $this->transactionApi = resolve(TransactionApi::class);
        $this->transactionApi->authenticate($organization->adn_api_login_id, $organization->adn_transaction_key);
    }

    /**
     * @param string|array $paymentData
     *
     * @return void
     */
    public function setPaymentData($paymentData)
    {
        $this->paymentData = $paymentData;
    }

    /**
     * Apply store credit, fulfill transaction and charge payment method
     *
     * @param string $note    Save a note to the transaction
     * @param bool   $fulfill Whether or not to fulfill the transaction
     * @param bool   $bypassGuards
     *
     * @return AuthorizeTransaction
     *
     * @throws CheckoutRestrictionException
     * @throws \Exception
     */
    public function checkout($note = null, $fulfill = true, $bypassGuards = false)
    {
        if (!in_array($this->transaction->status, ['new', 'failed'])) {
            throw new CheckoutRestrictionException('This transaction has already been paid for or voided');
        }

        try {
            DB::beginTransaction();

            $this->transaction->update([
                'status' => 'pending',
                'note'   => $note ?? $this->transaction->note,
            ]);

            $this->applyStoreCredit();

            $this->fulfill($fulfill, $bypassGuards);

            if ($this->storeProfile) {
                $this->storePaymentProfile();
            }

            if ($this->transaction->amount_due > 0) {
                $authorizeTransaction = $this->processPayment();
            }

            if ($this->transaction->amount_due !== 0) {
                throw new \Exception(
                    'Critical Error: ' . $this->transaction->amount_due < 0 ? 'Over' : 'Under' . 'payment detected.',
                    config('app.response_codes.server_error')
                );
            }

            DB::commit();
        } catch (PaymentException $e) {
            DB::rollback();
            $this->logChargeFailure($e);
            throw $e;
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        if (class_exists(OrderPlaced::class)) {
            event(new OrderPlaced($this->transaction->user, $this->transaction));
        }

        return $authorizeTransaction ?? null;
    }

    /**
     * Loop through transaction items to fulfill and mark transaction as fulfilled.
     *
     * @param bool $fulfill
     * @param bool $bypassGuards
     *
     * @return void
     *
     * @throws \Exception
     */
    private function fulfill($fulfill = true, $bypassGuards = false)
    {
        /** @var TransactionItem $transactionItem */
        foreach ($this->transaction->transactionItems as $transactionItem) {
            // set this here so we don't have to query to load it in uses below
            $transactionItem->setRelation('transaction', $this->transaction);
            $transactionItem->fulfill($fulfill, $bypassGuards);
        }

        $this->transaction->status = 'fulfilled';
        $this->transaction->save();

        $this->transaction->removeUnusedPromoCodes();
    }

    /**
     * @throws \Exception
     */
    private function applyStoreCredit()
    {
        $user = $this->transaction->user;
        $site = $this->transaction->site;

        $storeCreditApplied = 0;

        // Only apply store credit to inventory and subscription items
        $maxCredit = $this->transaction->storeCreditApplicableAmount();

        // Check for site specific store credit
        $siteSpecificStoreCreditBalance = $user->storeCreditBalance($site->id);
        $specificStoreCreditToUse       = $maxCredit < $siteSpecificStoreCreditBalance ?
            $maxCredit : $siteSpecificStoreCreditBalance;

        if ($specificStoreCreditToUse > 0) {
            // Apply site specific store credit
            $user->addStoreCredit(-$specificStoreCreditToUse, $this->transaction, $site);
            $this->transaction->amount_due -= $specificStoreCreditToUse;
            $storeCreditApplied            += $specificStoreCreditToUse;
            $maxCredit                     -= $specificStoreCreditToUse;
        }

        // Check for general store credit
        $generalStoreCreditBalance = $user->storeCreditBalance();
        $generalStoreCreditToUse   = $maxCredit < $generalStoreCreditBalance ? $maxCredit : $generalStoreCreditBalance;
        if ($generalStoreCreditToUse > 0) {
            // Apply general store credit
            $user->addStoreCredit(-$generalStoreCreditToUse, $this->transaction);
            $this->transaction->amount_due -= $generalStoreCreditToUse;
            $storeCreditApplied            += $generalStoreCreditToUse;
            $maxCredit                     -= $generalStoreCreditToUse;
        }

        if ($this->transaction->amount_due < 0) {
            throw new \Exception(
                'Math is hard.  Aborting transaction. ' . $maxCredit . ' | ' .
                $specificStoreCreditToUse . ' | ' . $generalStoreCreditToUse . ' | ' . $this->transaction->amount_due,
                config('app.response_codes.server_error')
            );
        }

        $this->transaction->store_credit_applied = $storeCreditApplied;
        $this->transaction->save();
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function storePaymentProfile()
    {
        /** @var User $user */
        $user = $this->transaction->user;
        $acm  = app(AuthorizeCustomerManager::class);
        $acm->setMerchant($user->organization);

        switch ($this->getPaymentType($this->paymentData)) {
            case 'track_1':
                $creditCard = self::splitTrackData($this->paymentData);

                $paymentProfileId = $acm->addCreditCard($creditCard)->id;
                break;

            case 'credit_card':
                $paymentProfileId = $acm->addCreditCard($this->paymentData)->id;
                break;

            case 'payment_profile':
                $paymentProfileId = $this->paymentData;
                break;

            case 'track_2':
            default:
                throw new \Exception('Unknown payment type');
                break;
        }

        return $paymentProfileId;
    }

    /**
     * @return AuthorizeTransaction
     *
     * @throws PaymentException
     */
    private function processPayment()
    {
        $transaction = $this->transaction;

        $transaction->increment('charge_attempts');

        try {
            $transactionApi = $this->transactionApi;

            switch ($this->getPaymentType($this->paymentData)) {
                case 'payment_profile':
                    $transactionDetails = $transactionApi->chargeProfile($transaction->amount_due, $transaction->user->authorize_id, $this->paymentData);
                    break;

                case 'track_1':
                    $transactionDetails = $transactionApi->chargeTrack($transaction->amount_due, $this->paymentData);
                    break;

                case 'credit_card':
                    $transactionDetails = $transactionApi->chargeCreditCard($transaction->amount_due, $this->paymentData);
                    break;

                case null:
                    throw new \Exception('Missing payment method');
                    break;

                case 'track_2':
                default:
                    throw new \Exception('Unknown payment type');
                    break;
            }

            /** @var AuthorizeTransaction $authorizeTransaction */
            $authorizeTransaction = $transaction->authorizeTransactions()->create([
                'organization_id'        => $transaction->organization_id,
                'adn_authorization_code' => $transactionDetails['authCode'],
                'adn_transaction_id'     => $transactionDetails['transId'],
                'amount'                 => $transactionDetails['amount'],
                'type'                   => $transactionDetails['type'],
                'last_four'              => $transactionDetails['lastFour'],
                'payment_profile_id'     => $this->getPaymentType($this->paymentData) === 'payment_profile' ? $this->paymentData : null,
            ]);

            $transaction->payment_applied += $transactionDetails['amount'];
            $transaction->amount_due      -= $transactionDetails['amount'];

            $transaction->save();

        } catch (\Exception $e) {
            throw new PaymentException($e->getMessage(), $e->getCode(), $e);
        }

        SyncTransaction::dispatch($authorizeTransaction);

        return $authorizeTransaction;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public static function getPaymentType($paymentData)
    {
        if (is_array($paymentData) && isset($paymentData['number']) && isset($paymentData['expiration']) && isset($paymentData['cvv'])) {
            return 'credit_card';
        } elseif (preg_match('/^%?B\d{0,19}\^[\w\s\/]{2,26}\^\d{7}\w*\??$/', $paymentData)) {
            return 'track_1';
        } elseif (preg_match('/;\d{0,19}=\d{7}\w*\?/', $paymentData)) {
            return 'track_2';
        } elseif (is_numeric($paymentData) && in_array(strlen($paymentData), [9,10])) {
            return 'payment_profile';
        }

        return null;
    }

    /**
     * @param $trackData
     *
     * @return array
     * @throws \Exception
     */
    private static function splitTrackData($trackData)
    {
        switch (self::getPaymentType($trackData)) {
            case 'track_1':
                $parts = explode('^', $trackData);
                $names = explode('/', $parts[1]);
                $year  = substr($parts[2], 0, 2);
                $month = substr($parts[2], 2, 2);
                $creditCard = [
                    'number'     => substr($parts[0], substr( $parts[0], 0, 1 ) === "%" ? 2 : 1),
                    'first_name' => trim($names[1]),
                    'last_name'  => trim($names[0]),
                    'expiration' => $month.'/'.$year,
                ];
                break;

            case 'track_2':
            case 'credit_card':
            case 'payment_profile':
            default:
                throw new \Exception('Unknown payment type');
                break;
        }

        return $creditCard;
    }

    /**
     * Append a new log to the array of charge failure logs.
     *
     * @param \Exception $exception
     *
     * @return void
     */
    private function logChargeFailure($exception)
    {
        $logs = $this->transaction->charge_failure_logs;

        $logs[] = $exception->getMessage();

        $this->transaction->increment('charge_attempts');
        $this->transaction->update([
            'status'              => 'failed',
            'charge_failure_logs' => $logs,
        ]);
    }

    /**
     * Process a refund on the transaction against the credit card
     * or for store credit. If the amount is zero, no refund is
     * applied.
     *
     * @param array   $transactionItems
     * @param boolean $applyAsStoreCredit
     *
     * @throws \Exception
     *
     * @return StoreCredit|AuthorizeTransaction
     */
    public function returnItems($transactionItems, $applyAsStoreCredit = false)
    {
        $transaction  = $this->transaction;
        $refundAmount = 0;

        if (!in_array($transaction->status, ['fulfilled', 'partially_refunded'])) {
            throw new \Exception('Transaction must be fulfilled before being returned.');
        }

        DB::beginTransaction();

        foreach ($transactionItems as $txItem) {
            /** @var TransactionItem $transactionItem */
            $transactionItem = TransactionItem::whereId($txItem['id'])->whereTransactionId($transaction->id)->firstOrFail();
            $refundAmount    += $transactionItem->return($txItem['quantity'], $txItem['restock'], $txItem['force'] ?? null);
        };

        if ($transaction->comp_reason || $refundAmount === 0) {
            DB::commit();
            return null;
        }

        $transaction->increment('refund', $refundAmount);

        if ($applyAsStoreCredit) {
            $refund = $transaction->user->addStoreCredit($refundAmount, $transaction);
        } else {
            $transactionApi     = $this->transactionApi;
            $payment            = $transaction->last_payment;

            SyncTransaction::dispatch($payment)->onConnection('sync');

            $payment->refresh();

            if (!$payment->refundable) {
                throw new ConflictHttpException('Transaction must be settled before it can be refunded.  Void transaction instead.');
            }

            $refundDetails = $transactionApi->refundTransaction($refundAmount, $payment->adn_transaction_id, $payment['last_four']);

            $refund = $transaction->authorizeTransactions()->create([
                'organization_id'        => $transaction->organization_id,
                'adn_authorization_code' => $refundDetails['authCode'],
                'adn_transaction_id'     => $refundDetails['transId'],
                'amount'                 => -$refundDetails['amount'],
                'type'                   => $refundDetails['type'],
            ]);
        }

        DB::commit();

        return $refund;
    }

    /**
     * Void transaction if pending
     *
     * @throws \Exception
     *
     * @return void
     */
    public function void()
    {
        $transactionApi = $this->transactionApi;
        $transaction    = $this->transaction;
        $payment        = $transaction->last_payment;

        if ($transaction->status !== 'fulfilled' || !$payment instanceof AuthorizeTransaction) {
            throw new ConflictHttpException('Payment not submitted.');
        }

        dispatch_now(new SyncTransaction($payment));

        $payment->refresh();

        if (!$payment->voidable) {
            throw new ConflictHttpException('Transaction must be pending settlement in order to be voided.  Refund transaction instead.');
        }

        foreach ($transaction->transactionItems as $transactionItem) {
            /** @var TransactionItem $transactionItem */
            $transactionItem = TransactionItem::whereId($transactionItem['id'])->firstOrFail();
            $transactionItem->return();
        };

        $transactionApi->voidTransaction($payment->adn_transaction_id);

        SyncTransaction::dispatch($payment);

        $transaction->status = 'void';
        $transaction->save();
    }

    public function comp($reason)
    {
        $this->transaction->update([
            'comp_reason' => $reason,
            'discount'    => $this->transaction->subtotal,
            'tax'         => 0,
            'total'       => 0,
            'amount_due'  => 0,
        ]);
    }
}
