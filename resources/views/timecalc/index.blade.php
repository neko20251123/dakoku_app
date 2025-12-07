{{-- resources/views/timecalc/index.blade.php --}}
@extends('layouts.app')

@section('title', '打刻計算ツール')

@section('content')
    <h1>打刻計算ツール</h1>

    <p>カテゴリと時間を入力して、打刻用の時間を自動計算します。</p>

    {{-- エラー表示（あとでバリデーション実装したときに使う） --}}
    @if ($errors->any())
        <div class="error-messages">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- メインフォーム（※まだ name や action は仮） --}}
    <form method="POST" action="{{ route('timecalc.calculate') }}">
        @csrf

        {{-- 入力行エリア： --}}
        <div class="task-rows">

            {{-- 1行目 --}}
            <div class="task-row">
                <label>
                    カテゴリ
                    <select name="rows[0][category]">
                        <option value="">選択してください</option>
                        {{-- カテゴリは後でマスタから埋める --}}
                        <option value="task1">タスク1（仮）</option>
                        <option value="task2">タスク2（仮）</option>
                        <option value="task3">タスク3（仮）</option>
                    </select>
                </label>

                <label>
                    開始
                    <input type="time" name="rows[0][start]" placeholder="09:00">
                </label>

                <label>
                    終了
                    <input type="time" name="rows[0][end]" placeholder="12:00">
                </label>

                <span class="task-duration">
                    時間：<span>--</span> h
                </span>
            </div>
        </div>

        {{-- 行追加ボタン（中身はまだ未実装。あとでJSやサーバ側で実装） --}}
        <button type="button">
            ＋ 行を追加
        </button>

        {{-- 計算ボタン --}}
        <div class="actions">
            <button type="submit">
                計算する
            </button>
        </div>
    </form>

    {{-- 集計結果表示（今は枠だけ） --}}
    <section class="summary">
        <h2>カテゴリ別合計</h2>
        <ul>
            <li>開発：-- h</li>
            <li>調査：-- h</li>
            <li>会議：-- h</li>
        </ul>

        <h2>合計時間</h2>
        <p>-- h</p>

        <h2>コピペ用テキスト</h2>
        <textarea rows="5" cols="40" readonly>ここに結果が入ります（あとで実装）</textarea>
    </section>
@endsection