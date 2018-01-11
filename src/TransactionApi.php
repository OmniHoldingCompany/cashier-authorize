<?php

namespace Laravel\CashierAuthorizeNet;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller\CreateTransactionController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Class TransactionApi
 * @package Laravel\CashierAuthorizeNet
 */
class TransactionApi extends MerchantApi
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param integer $pennies Amount to be charged, in cents
     * @param integer $CustomerProfileId
     * @param integer $paymentProfileId
     * @param integer $invoiceId
     * @param array   $options
     *
     * @return array
     * @throws \Exception
     */
    public function charge($pennies, $CustomerProfileId, $paymentProfileId, $invoiceId = null, array $options = [])
    {
        $options = array_merge([
            'currency' => self::preferredCurrency(),
        ], $options);

        $paymentProfile = new AnetAPI\PaymentProfileType();
        $paymentProfile->setPaymentProfileId($paymentProfileId);

        $profileToCharge = new AnetAPI\CustomerProfilePaymentType();
        $profileToCharge->setCustomerProfileId($CustomerProfileId);
        $profileToCharge->setPaymentProfile($paymentProfile);

        $order = new AnetAPI\OrderType;
        $order->setDescription($options['description'] ?? null);
        $order->setInvoiceNumber($invoiceId);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType('authCaptureTransaction');
        $transactionRequest->setAmount(self::convertPenniesToDollars($pennies));

        $transactionRequest->setCurrencyCode($options['currency']);
        $transactionRequest->setOrder($order);
        $transactionRequest->setProfile($profileToCharge);

        $transactionResponse = $this->buildAndExecuteRequest($transactionRequest);

        return [
            'authCode' => $transactionResponse->getAuthCode(),
            'transId'  => $transactionResponse->getTransId(),
            'lastFour' => substr($transactionResponse->getAccountNumber(), -4),
        ];
    }

    /**
     * Refund money to a the credit card used in the transaction.
     *
     * @param integer $pennies Amount to be refunded, in cents
     * @param integer $transactionId
     * @param integer $lastFour
     *
     * @return integer
     * @throws \Exception
     */
    public function refundTransaction($pennies, $transactionId, $lastFour)
    {
        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType('refundTransaction');
        $transactionRequest->setAmount(self::convertPenniesToDollars($pennies));

        $card = new AnetAPI\CreditCardType();
        $card->setCardCode($lastFour);

        $paymentDetails = new AnetAPI\PaymentType();
        $paymentDetails->setCreditCard($card);

        $transactionRequest->setRefTransId($transactionId);
        $transactionRequest->setPayment($paymentDetails);

        $transactionResponse = $this->buildAndExecuteRequest($transactionRequest);

        return $transactionResponse->getTransId();
    }

    /********************
     * HELPER FUNCTIONS *
     ********************/

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
     * Get the currency used by the entity.
     *
     * @return string
     */
    private static function preferredCurrency()
    {
        return Cashier::usesCurrency();
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
        $request = new AnetAPI\CreateTransactionRequest();
        $request->setRefId('ref' . time());
        $request->setTransactionRequest($transactionRequest);
        $request->setMerchantAuthentication($this->merchantAuthentication);

        $controller = new CreateTransactionController($request);

        /** @var AnetAPI\CreateTransactionResponse $response */
        $response = $controller->executeWithApiResponse(ANetEnvironment::SANDBOX);

        if (is_null($response)) {
            throw new \Exception("ERROR: NO RESPONSE", config('app.response_codes.server_error'));
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $errorMessages = $response->getMessages()->getMessage();
            switch ($errorMessages[0]->getCode()) {
                case 'E00013': // Customer Payment Profile ID is invalid.
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

        $transactionResponse = $response->getTransactionResponse();

        if (is_null($transactionResponse)) {
            throw new \Exception('ERROR: NO TRANSACTION RESPONSE', 1);
        }

        switch ($transactionResponse->getResponseCode()) {
            case "1": // Success
                break;

            case "2": // General decline, no further details.
            case "3":
                // A referral to a voice authorization center was received.
                // Please call the appropriate number below for a voice authorization.
                //   For American Express call: (800) 528-2121
                //   For Diners Club call: (800) 525-9040
                //   For Discover/Novus call: (800) 347-1111
                //   For JCB call : (800) 522-9345
                //   For Visa/Mastercard call: (800) 228-1122
                // Once an authorization is issued, you can then submit the transaction through your Virtual Terminal as a Capture Only transaction.
            case "4": // The code returned from the processor indicating that the card used needs to be picked up.
            case "6": // The credit card number is invalid.
            case "11": // A transaction with identical amount and credit card information was submitted within the previous two minutes.
                $transactionErrors = $transactionResponse->getErrors();
                throw new BadRequestHttpException($transactionErrors[0]->getErrorText());
                break;

            default:
                $transactionErrors = $transactionResponse->getErrors();
                throw new \Exception('Unknown response code: ' . $transactionResponse->getResponseCode() . ' - ' . $transactionErrors[0]->getErrorText());
                break;
        }

        return $transactionResponse;
    }
}
