<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoCallController;

Route::post('/video/request/{token}', [VideoCallController::class, 'request'])
    ->name('api.video.request');

Route::post('/video/accept/{token}', [VideoCallController::class, 'accept'])
    ->name('api.video.accept');

Route::post('/video/stop/{token}', [VideoCallController::class, 'stop'])
    ->name('api.video.stop');
