<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TimeCalcController extends Controller
{
    public function index()
    {
        return view('timecalc.index');
    }

    public function calculate(Request $request)
    {
        // ひとまず、送られてきた値を確認するだけ
        // あとでここにバリデーション + 計算ロジックを入れていく
        dd($request->all());
    }
}
