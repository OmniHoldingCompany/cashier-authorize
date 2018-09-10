<?php

namespace Laravel\CashierAuthorizeNet\Events;

use App\AuthorizeTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class TransactionVoided
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var AuthorizeTransaction
     */
    public $authorizeTransaction;

    /**
     * Create a new event instance.
     *
     * @param  AuthorizeTransaction $authorizeTransaction
     */
    public function __construct(AuthorizeTransaction $authorizeTransaction)
    {
        $this->authorizeTransaction = $authorizeTransaction;
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
