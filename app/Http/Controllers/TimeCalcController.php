<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TimeCalcController extends Controller
{
    public function index()
    {
        return view('timecalc.index');
    }
}
