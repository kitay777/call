<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // private/presence を使う場合の認可エンドポイント（publicだけでも置いてOK）
        Broadcast::routes();

        // Broadcast チャンネル定義を読み込み
        require base_path('routes/channels.php');
    }
}
