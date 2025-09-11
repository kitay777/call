<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\OperatorController;

use App\Http\Controllers\OperationController;

// オペレーター本人用（要ログイン）
Route::middleware(['auth'])->group(function () {
    Route::get('/operator', [OperatorController::class, 'dashboard'])->name('operator.dashboard');
    Route::post('/operator/state', [OperatorController::class, 'updateSelfState'])->name('operator.state'); 
});

// 運用管理画面（管理者）
Route::middleware(['auth'])->group(function () {
    Route::get('/operation/operators', [OperationController::class, 'index'])
        ->name('operation.operators.index');
    Route::post('/operation/operators/{user}/state', [OperationController::class, 'updateState'])
        ->name('operation.operators.update')->whereNumber('user');
});

Route::prefix('reception')->group(function () {
    Route::get('start', [ReceptionController::class, 'start'])->name('reception.start');
    Route::post('start', [ReceptionController::class, 'startPost'])->name('reception.start.post');

    Route::get('waiting/{token}', [ReceptionController::class, 'waiting'])->name('reception.waiting');
    Route::get('status/{token}', [ReceptionController::class, 'status'])->name('reception.status'); // JSONポーリング

    Route::get('in-progress/{token}', [ReceptionController::class, 'inProgress'])->name('reception.in_progress');
    Route::post('advance/{token}', [ReceptionController::class, 'advance'])->name('reception.advance'); // 次ステップへ

    Route::get('verify/{token}', [ReceptionController::class, 'verify'])->name('reception.verify');
    Route::get('apply/{token}', [ReceptionController::class, 'apply'])->name('reception.apply');
    Route::get('important/{token}', [ReceptionController::class, 'important'])->name('reception.important');
    Route::get('sign/{token}', [ReceptionController::class, 'sign'])->name('reception.sign');
    Route::post('sign/{token}', [ReceptionController::class, 'signStore'])->name('reception.sign.store');
    Route::get('done/{token}', [ReceptionController::class, 'done'])->name('reception.done');
});



Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
