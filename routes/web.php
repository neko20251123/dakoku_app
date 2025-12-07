<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TimeCalcController;

// 初期のwelcomeのルート 不要なのであとで消す
// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', [TimeCalcController::class, 'index'])->name('timecalc.index');

Route::post('/calculate', [TimeCalcController::class, 'calculate'])
    ->name('timecalc.calculate');
