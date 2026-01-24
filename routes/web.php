<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TimeCalcController;

// 初期のwelcomeのルート 不要なのであとで消す
// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', [TimeCalcController::class, 'index'])->name('timecalc.index');

// 非同期じゃない時の計算処理
// 非同期を実装したのでコメントアウト
// Route::post('/calculate', [TimeCalcController::class, 'calculate'])
//     ->name('timecalc.calculate');

// 非同期通信
Route::post('/calculate-json', [TimeCalcController::class, 'calculateJson'])
    ->name('timecalc.calculateJson');
