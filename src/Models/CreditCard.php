<?php

namespace Laravel\CashierAuthorizeNet\Models;

use App\Organization;
use App\Scoping\Traits\TenantScopedModelTrait;
use App\Scoping\Traits\UserScopedModelTrait;
use App\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations as Relations;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Class CreditCard
 *
 * @package App
 */
class CreditCard extends Model
{
    use TenantScopedModelTrait;
    use UserScopedModelTrait;
    use SoftDeletes;

    /**
     * The standard format for credit card expiration date
     *
     * @var string
     */
    const EXPIRATIONFORMAT = 'm/y';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'organization_id',
        'user_id',
        'primary',
        'number',
        'type',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'organization_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [
        'organization_id' => 'integer',
        'id'              => 'string',
        'user_id'         => 'integer',
        'primary'         => 'boolean',
        'number'          => 'string',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'expiration',
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'expires_at',
    ];

    /**
     * @return Relations\BelongsTo
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Relations\HasMany
     */
    public function AuthorizeTransactions()
    {
        return $this->hasMany(AuthorizeTransaction::class);
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', Carbon::now());
    }

    /**
     * @param Builder $query
     * @param integer $seconds
     *
     * @return Builder
     */
    public function scopeExpiring($query, $seconds)
    {
        return $query->where('expires_at', '<=', Carbon::now()->subSeconds($seconds));
    }

    /**
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopePrimary($query)
    {
        return $query->where('primary', true);
    }

    /**
     * Alias for 'expires'
     *
     * @return string
     */
    public function getExpirationAttribute()
    {
        return $this->getExpiresAttribute();
    }

    /**
     * Get the expiration date in standard format
     *
     * @return string
     */
    public function getExpiresAttribute()
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', $this->expires_at)->format(self::EXPIRATIONFORMAT);
    }
}
