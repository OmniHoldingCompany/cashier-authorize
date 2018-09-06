<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAuthorizeTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        if (!Schema::hasTable('authorize_transactions')) {
            Schema::create('authorize_transactions', function (Blueprint $table) {
                $transactionTypes = [
                    'authCaptureTransaction',
                    'refundTransaction',
                    'voidTransaction',
                    'authOnlyTransaction',
                    'priorAuthCaptureTransaction',
                    'captureOnlyTransaction',
                    'getDetailsTransaction',
                    'authOnlyContinueTransaction',
                ];

                $statuses = [
                    'authorizedPendingCapture',
                    'capturedPendingSettlement',
                    'communicationError',
                    'refundSettledSuccessfully',
                    'refundPendingSettlement',
                    'approvedReview',
                    'declined',
                    'couldNotVoid',
                    'expired',
                    'generalError',
                    'failedReview',
                    'settledSuccessfully',
                    'settlementError',
                    'underReview',
                    'voided',
                    'FDSPendingReview',
                    'FDSAuthorizedPendingReview',
                    'returnedItem',
                ];

                $table->increments('id');
                $table->integer('organization_id')->unsigned();
                $table->integer('transaction_id')->unsigned();
                $table->string('adn_authorization_code')->nullable();
                $table->string('adn_transaction_id')->nullable();
                $table->enum('adn_status', $statuses)->nullable();
                $table->enum('type', $transactionTypes);
                $table->string('payment_profile_id')->nullable();
                $table->string('last_four', 4);
                $table->integer('amount');

                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations');
                $table->foreign('transaction_id')->references('id')->on('transactions');
                $table->foreign('payment_profile_id')->references('id')->on('credit_cards');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('authorize_transactions');
    }
}
