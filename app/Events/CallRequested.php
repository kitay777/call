<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class CallRequested implements ShouldBroadcast
{
    public function __construct(
        public string $token,
        public int $ts   // timestamp
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('calls');
    }

    public function broadcastAs(): string
    {
        return 'CallRequested';
    }
}
