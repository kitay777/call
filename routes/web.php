<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\OperationController;

/*
|--------------------------------------------------------------------------
| Reception endpoints (iPad / client side)
|--------------------------------------------------------------------------
| ※ ルート重複なし。token/code は英数ハイフンのみ許可。
*/
Route::post('/reception/ack-important/{token}', [ReceptionController::class, 'ackImportant']);


Route::prefix('reception')->group(function () {
    Route::get('heartbeat/{token}', [ReceptionController::class, 'heartbeat'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.heartbeat');

    Route::get('start',  [ReceptionController::class, 'start'])->name('reception.start');
    Route::post('start', [ReceptionController::class, 'startPost'])->name('reception.start.post');

    Route::get('waiting/{token}', [ReceptionController::class, 'waiting'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.waiting');

    // 進行状況のポーリング(JSON)
    Route::get('status/{token}', [ReceptionController::class, 'status'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.status');

    // ステップ進行
    Route::post('advance/{token}', [ReceptionController::class, 'advance'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.advance');

    Route::get('in-progress/{token}', [ReceptionController::class, 'inProgress'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.in_progress');

    Route::get('verify/{token}', [ReceptionController::class, 'verify'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.verify');

    Route::get('apply/{token}', [ReceptionController::class, 'apply'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.apply');

    Route::get('important/{token}', [ReceptionController::class, 'important'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.important');

    Route::get('sign/{token}', [ReceptionController::class, 'sign'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.sign');
    Route::post('sign/{token}', [ReceptionController::class, 'signStore'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.sign.store');

    // iPad からの通話通知等
    Route::post('notify-video/{token}', [ReceptionController::class, 'notifyVideo'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.notifyVideo');

    Route::get('done/{token}', [ReceptionController::class, 'done'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('reception.done');
});

/*
|--------------------------------------------------------------------------
| Talk (video) endpoints
|--------------------------------------------------------------------------
*/
Route::get('/talk/room/{code}', [ReceptionController::class, 'videoClientByCode'])
    ->where('code', '[A-Za-z0-9\-]+')
    ->name('talk.client.by_code');

// 発信者（iPad等）側：受付トークンで保護（ログイン不要）
Route::get('/talk/client/{token}', [ReceptionController::class, 'videoClient'])
    ->where('token', '[A-Za-z0-9\-]+')
    ->name('talk.client');

// オペレーター側（ログイン必須）
Route::middleware('auth')->get('/talk/ops/{roomId}', [OperatorController::class, 'videoOps'])
    ->where('roomId', '[A-Za-z0-9\-]+')
    ->name('talk.ops');

/*
|--------------------------------------------------------------------------
| Operator dashboards & sessions
|--------------------------------------------------------------------------
| ✅ /operator/session/{token} は “実ページ” (OperatorController@session) に固定
|    → これにより、既存ブックマークの UI は変わりません。
*/
Route::middleware('auth')->group(function () {
    // 従来通りのセッション画面
    Route::get('/operator/session/{token}', [OperatorController::class, 'session'])
        ->where('token', '[A-Za-z0-9\-]+')
        ->name('operator.session');

    // オペ本人用ダッシュボード & 自身の稼働状態更新
    Route::get('/operator', [OperatorController::class, 'dashboard'])->name('operator.dashboard');
    Route::post('/operator/state', [OperatorController::class, 'updateSelfState'])->name('operator.state');
});

/*
|--------------------------------------------------------------------------
| Operations (admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'can:manage-operators'])->group(function () {
    Route::get('/admin',    [OperationController::class,'index'])->name('admin.dashboard');
    Route::get('/operation/operators',    [OperationController::class,'index'])->name('operation.operators.index');
    Route::get('/operation/waiting-list', [OperationController::class,'waitingList'])->name('operation.waitingList');
    Route::post('/operation/operators/{user}/state', [OperationController::class, 'updateState'])
        ->name('operation.operators.update');
});

/*
|--------------------------------------------------------------------------
| App / Profile / Auth
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => redirect()->route('reception.start'));

Route::get('/dashboard', fn () => Inertia::render('Dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile',  [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',[ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile',[ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
