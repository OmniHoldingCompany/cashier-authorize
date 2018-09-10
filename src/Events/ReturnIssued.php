<?php

namespace Laravel\CashierAuthorizeNet\Events;

use App\AuthorizeTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class RefundIssued
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var AuthorizeTransaction */
    public $authorizeTransaction;

    /** @var array */
    public $itemsReturned;

    /**
     * Create a new event instance.
     *
     * @param  AuthorizeTransaction $authorizeTransaction
     */
    public function __construct(AuthorizeTransaction $authorizeTransaction, $itemsReturned)
    {
        $this->authorizeTransaction = $authorizeTransaction;
        $this->itemsReturned        = $itemsReturned;
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
