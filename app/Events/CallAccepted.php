<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CallAccepted implements ShouldBroadcast
{
    public function __construct(
        public string $roomId,
        public string $operatorId
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('calls');
    }

    public function broadcastAs(): string
    {
        return 'CallAccepted';
    }
}
