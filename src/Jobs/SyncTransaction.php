<?php

namespace Laravel\CashierAuthorizeNet\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Laravel\CashierAuthorizeNet\Models\AuthorizeTransaction;
use Laravel\CashierAuthorizeNet\TransactionApi;

class SyncTransaction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Authorize transaction to be updated
     *
     * @var AuthorizeTransaction
     */
    private $authTransaction;

    /**
     * Create a new job instance.
     *
     * @param AuthorizeTransaction $authTransaction
     */
    public function __construct(AuthorizeTransaction $authTransaction)
    {
        $this->authTransaction = $authTransaction;
    }

    /**
     * Execute the job.
     *
     * @param TransactionApi $transactionApi
     *
     * @return void
     * @throws \Exception
     */
    public function handle(TransactionApi $transactionApi)
    {
        $organization = $this->authTransaction->organization;

        $transactionApi->authenticate($organization->adn_api_login_id, $organization->adn_transaction_key);

        $authData = $transactionApi->getTransactionDetails($this->authTransaction->adn_transaction_id);

        $this->authTransaction->update([
            'adn_status' => $authData['status'],
        ]);
    }
}
