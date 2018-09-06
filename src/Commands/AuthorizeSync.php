<?php

namespace Laravel\CashierAuthorizeNet\Commands\Authorize;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\CashierAuthorizeNet\Jobs\SyncTransaction;
use Laravel\CashierAuthorizeNet\Models\AuthorizeTransaction;

class AuthorizeSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'authorize:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync transaction data from authorize.net to the application.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $authTransactions = AuthorizeTransaction::where('updated_at', '>=', Carbon::now()->subDays(30))
            ->whereNotNull('adn_transaction_id')
            ->get();

        $authTransactions->each(function($authTransaction) {
            SyncTransaction::dispatch($authTransaction);
        });
    }
}
