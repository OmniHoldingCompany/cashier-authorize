<?php

use App\User;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\Carbon;
use Laravel\CashierAuthorizeNet\Models\AuthorizeTransaction;
use Laravel\CashierAuthorizeNet\Models\CreditCard;

/** @var Factory $factory */
$factory->define(AuthorizeTransaction::class, function (Faker\Generator $faker, $attributes) {
    $creditCard = isset($attributes['payment_profile_id']) ? CreditCard::find($attributes['payment_profile_id']) : factory(CreditCard::class)->create();

    return [
        'organization_id'        => config('app.organization_id'),
        'transaction_id'         => $faker->unique()->numerify('#############'),
        'adn_authorization_code' => $faker->unique()->numerify('#############'),
        'adn_transaction_id'     => $faker->unique()->numerify('#############'),
        'adn_status'             => 'settledSuccessfully',
        'type'                   => 'visa',
        'payment_profile_id'     => $creditCard->id,
        'amount'                 => 0,
    ];
});

/** @var Factory $factory */
$factory->define(CreditCard::class, function (Faker\Generator $faker, $attributes) {
    $user = isset($attributes['user_id']) ? User::find($attributes['user_id']) : factory(User::class)->create();

    return [
        'organization_id' => config('app.organization_id'),
        'id'              => $faker->unique()->numerify('#########'),
        'user_id'         => $user->id,
        'number'          => 'XXXX'.$faker->numerify('####'),
        'expires_at'      => Carbon::now()->addYears(2),
        'type'            => $faker->randomElement(['Visa', 'Mastercard', 'Discover', 'AmericanExpress', 'DinersClub', 'JCB']),
    ];
});
