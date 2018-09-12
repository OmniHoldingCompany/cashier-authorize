<?php

namespace Laravel\CashierAuthorizeNet\Events;

use App\AuthorizeTransaction;
use App\StoreCredit;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class RefundIssued
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var AuthorizeTransaction|StoreCredit */
    public $refund;

    /**
     * Create a new event instance.
     *
     * @param  AuthorizeTransaction|StoreCredit $refund
     */
    public function __construct($refund)
    {
        $this->refund = $refund;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
