<?php

namespace Laravel\CashierAuthorizeNet\Models;

use App\Organization;
use App\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class AuthorizeTransaction
 *
 * @package App
 */
class AuthorizeTransaction extends Model
{
    /**
     * Transaction statuses that qualify for being voided.
     *
     * @var array
     */
    const VOIDABLESTATUSES = [
        'authorizedPendingCapture',
        'capturedPendingSettlement',
        'authorizedPendingRelease',
        'FDSPendingReview',
    ];

    /**
     * Transaction statuses that qualify for being returned.
     *
     * @var array
     */
    const REFUNDABLESTATUSES = [
        'settledSuccessfully',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'organization_id',
        'transaction_id',
        'type',
        'adn_authorization_code',
        'adn_transaction_id',
        'adn_status',
        'amount',
        'last_four',
        'payment_profile_id',
    ];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'organization_id'    => 'integer',
        'transaction_id'     => 'integer',
        'adn_transaction_id' => 'integer',
        'amount'             => 'integer',
        'voidable'           => 'boolean',
        'refundable'         => 'boolean',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'voidable',
        'refundable',
    ];

    /**
     * Can this transaction be voided.
     *
     * @return boolean
     */
    public function getVoidableAttribute() {
        return in_array($this->adn_status, self::VOIDABLESTATUSES);
    }

    /**
     * Can this transaction be refunded.
     *
     * @return boolean
     */
    public function getRefundableAttribute() {
        return in_array($this->adn_status, self::REFUNDABLESTATUSES);
    }

    /**
     * Get the transaction for this payment or refund.
     *
     * @return Relations\BelongsTo
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Get the organization that this payment or refund belongs to.
     *
     * @return Relations\BelongsTo
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the credit card used for this payment or refund.
     *
     * @return Relations\BelongsTo
     */
    public function creditCard()
    {
        return $this->belongsTo(CreditCard::class);
    }

    /**
     * Scope a query to only include payments.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePayments($query)
    {
        return $query->where('type', 'authCaptureTransaction');
    }

    /**
     * Scope a query to only include refunds.
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeRefunds($query)
    {
        return $query->where('type', 'refundTransaction');
    }
}
