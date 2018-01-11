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
    protected function getMerchantAuthentication()
    {
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName(getenv('ADN_API_LOGIN_ID'));
        $merchantAuthentication->setTransactionKey(getenv('ADN_TRANSACTION_KEY'));

        return $merchantAuthentication;
    }

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

    private static function getPaymentDetails($cardDetails)
    {
        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($cardDetails['number']);

        if (!empty($cardDetails['expiration'])) {
            $creditCard->setExpirationDate($cardDetails['expiration']);
        }

        if (!empty($cardDetails['cvv'])) {
            $creditCard->setCardCode($cardDetails['cvv']);
        }

        $paymentDetails = new AnetAPI\PaymentType();
        $paymentDetails->setCreditCard($creditCard);

        return $paymentDetails;
    }

    /**
     * Convert pennies to dollars for Authorize.net
     *
     * @param integer $pennies
     *
     * @return float
     */
    private static function convertPenniesToDollars($pennies)
    {
        $money          = new Money($pennies, new Currency('USD'));
        $currencies     = new ISOCurrencies();
        $moneyFormatter = new DecimalMoneyFormatter($currencies);

        return $moneyFormatter->format($money);
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @return string
     */
    private static function preferredCurrency()
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the Stripe supported currency used by the entity.
     *
     * @param string  $type
     * @param integer $pennies
     *
     * @return string
     */
    private static function createTransactionRequest($type, $pennies)
    {
        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType($type);
        $transactionRequest->setAmount(self::convertPenniesToDollars($pennies));

        return $transactionRequest;
    }

    /**
     * Execute a given transaction request
     *
     * @param AnetAPI\TransactionRequestType $transactionRequest
     *
     * @return AnetAPI\TransactionResponseType
     * @throws \Exception
     */
    private function buildAndExecuteRequest(AnetAPI\TransactionRequestType $transactionRequest)
    {
        $merchantAuthentication = $this->getMerchantAuthentication();

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setRefId('ref' . time());
        $request->setTransactionRequest($transactionRequest);
        $request->setMerchantAuthentication($merchantAuthentication);

        $requestor = new Requestor();
        $requestor->prepare($request);

        $controller = new CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00084':
                case 'E00105':
                    throw new BadRequestHttpException($errorMessages[0]->getText());
                    break;
                case 'E00027':
                    // Transaction error caught below
                    break;
                default:
                    throw new \Exception($errorMessages[0]->getText());
                    break;
            }
        }

        /** @var AnetAPI\TransactionResponseType $tresponse */
        $tresponse = $response->getTransactionResponse();

        if (is_null($tresponse)) {
            throw new \Exception('ERROR: NO TRANSACTION RESPONSE', 1);
        }

        switch ($tresponse->getResponseCode()) {
            case "1":
                // Success
                break;

            case "2":
                // General decline, no further details.
                throw new BadRequestHttpException($tresponse->getResponseText());
                break;
            case "3":
                // A referral to a voice authorization center was received.
                // Please call the appropriate number below for a voice authorization.
                //   For American Express call: (800) 528-2121
                //   For Diners Club call: (800) 525-9040
                //   For Discover/Novus call: (800) 347-1111
                //   For JCB call : (800) 522-9345
                //   For Visa/Mastercard call: (800) 228-1122
                // Once an authorization is issued, you can then submit the transaction through your Virtual Terminal as a Capture Only transaction.
                throw new BadRequestHttpException($tresponse->getResponseText() . ' Voice authorization required.');
                break;
            case "4":
                throw new BadRequestHttpException($tresponse->getResponseText() . ' The code returned from the processor indicating that the card used needs to be picked up.');
                break;
            case "11":
                // A transaction with identical amount and credit card information was submitted within the previous two minutes.
                throw new BadRequestHttpException($tresponse->getResponseText());
                break;

            default:
                throw new \Exception('Unknown response code: ' . $tresponse->getResponseCode());
        }

        return $tresponse;
    }
}
