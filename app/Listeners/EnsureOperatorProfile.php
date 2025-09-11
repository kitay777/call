<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;          // ← これを use
use App\Models\OperatorProfile;

class EnsureOperatorProfile
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void   // ← 正しい型は Login
    {
        OperatorProfile::firstOrCreate(
            ['user_id' => $event->user->id],
            ['state'   => 'off_today']
        );
    }
}
