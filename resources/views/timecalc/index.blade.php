{{-- resources/views/timecalc/index.blade.php --}}
@extends('layouts.app')

@section('title', '打刻計算ツール')

@section('content')
    <h1>打刻計算ツール</h1>

    <p>カテゴリと時間を入力して、打刻用の時間を自動計算します。</p>

    {{-- エラー表示 --}}
    @if ($errors->any())
        <div style="padding: 8px; border: 1px solid #f00; margin: 12px 0;">
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 仕様説明（要件の見える化） --}}
    <p class="note">
        ※ 時刻の入力ルール<br>
        ・時刻は15分刻み（00/15/30/45）で入力してください（0.25h単位で計算します）<br>
        ・終了時刻が開始時刻より前の場合は、翌日として計算します<br>
        ・0時間（開始＝終了）は入力できません<br>
        ・1行あたりの作業時間は <strong>最大20時間まで</strong> です（入力ミス防止のため）
    </p>

    @php
        // old優先（バリデーションで戻ってきたときに行数も復元したい）
        $rows = old('rows') ?? [ ['category' => '', 'start' => '', 'end' => ''] ];

        $result = session('result');
        $totals = $result['categoryTotals'] ?? [];
        $labels = $result['categoryLabels'] ?? [];

        $minutesOptions = ['00', '15', '30', '45'];
    @endphp

    <form id="timecalc-form" method="POST" action="{{ route('timecalc.calculate') }}">
        @csrf

        <div class="task-rows" id="task-rows">
            @foreach ($rows as $i => $row)
                @php
                    $start = $row['start'] ?? '';
                    $end   = $row['end'] ?? '';

                    [$sh, $sm] = ($start && str_contains($start, ':')) ? explode(':', $start) : ['', ''];
                    [$eh, $em] = ($end && str_contains($end, ':')) ? explode(':', $end) : ['', ''];
                @endphp

                <div class="task-row" data-row style="margin: 8px 0; padding: 8px; border: 1px solid #ddd;">
                    <label style="display:block; margin-bottom:6px;">
                        カテゴリ
                        <select name="rows[{{ $i }}][category]">
                            <option value="">選択してください</option>
                            <option value="task1" @selected(($row['category'] ?? '') === 'task1')>タスク1（仮）</option>
                            <option value="task2" @selected(($row['category'] ?? '') === 'task2')>タスク2（仮）</option>
                            <option value="task3" @selected(($row['category'] ?? '') === 'task3')>タスク3（仮）</option>
                        </select>
                    </label>

                    {{-- UIは時・分をselectで固定 --}}
                    <label style="margin-right:12px;">
                        開始
                        <select name="rows_ui[{{ $i }}][start_h]" data-time-select>
                            <option value="">--</option>
                            @for ($h = 0; $h <= 23; $h++)
                                @php $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT); @endphp
                                <option value="{{ $hh }}" @selected($sh === $hh)>{{ $hh }}</option>
                            @endfor
                        </select>
                        :
                        <select name="rows_ui[{{ $i }}][start_m]" data-time-select>
                            <option value="">--</option>
                            @foreach ($minutesOptions as $mm)
                                <option value="{{ $mm }}" @selected($sm === $mm)>{{ $mm }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label style="margin-right:12px;">
                        終了
                        <select name="rows_ui[{{ $i }}][end_h]" data-time-select>
                            <option value="">--</option>
                            @for ($h = 0; $h <= 23; $h++)
                                @php $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT); @endphp
                                <option value="{{ $hh }}" @selected($eh === $hh)>{{ $hh }}</option>
                            @endfor
                        </select>
                        :
                        <select name="rows_ui[{{ $i }}][end_m]" data-time-select>
                            <option value="">--</option>
                            @foreach ($minutesOptions as $mm)
                                <option value="{{ $mm }}" @selected($em === $mm)>{{ $mm }}</option>
                            @endforeach
                        </select>
                    </label>

                    {{-- Controller互換用 hidden（ここに HH:MM を入れて送信する） --}}
                    <input type="hidden" name="rows[{{ $i }}][start]" value="{{ $start }}">
                    <input type="hidden" name="rows[{{ $i }}][end]"   value="{{ $end }}">

                    <span class="task-duration">
                        時間：<strong>{{ isset($result['rowHours'][$i]) ? number_format($result['rowHours'][$i], 2) : '--' }}</strong> h
                    </span>

                    <button type="button" data-remove>削除</button>
                </div>
            @endforeach
        </div>

        <button type="button" id="add-row">＋ 行を追加</button>

        <div class="actions" style="margin-top: 12px;">
            <button type="submit">計算する</button>
        </div>
    </form>

    {{-- 結果表示 --}}
    <section class="summary">
        <h2>カテゴリ別合計</h2>
            <ul id="category-totals"></ul>
        <h2>合計時間</h2>
        <p><strong id="total-hours">--</strong> h</p>
        <h2>コピペ用テキスト</h2>
        <textarea id="copy-text" rows="6" cols="40" readonly></textarea>
    </section>
    {{-- 追加行テンプレ（時・分select固定 + hidden start/end） --}}
    <template id="task-row-template">
        <div class="task-row" data-row style="margin: 8px 0; padding: 8px; border: 1px solid #ddd;">
            <label style="display:block; margin-bottom:6px;">
                カテゴリ
                <select name="rows[__INDEX__][category]">
                    <option value="">選択してください</option>
                    <option value="task1">タスク1（仮）</option>
                    <option value="task2">タスク2（仮）</option>
                    <option value="task3">タスク3（仮）</option>
                </select>
            </label>

            <label style="margin-right:12px;">
                開始
                <select name="rows_ui[__INDEX__][start_h]" data-time-select>
                    <option value="">--</option>
                    <option value="00">00</option><option value="01">01</option><option value="02">02</option><option value="03">03</option>
                    <option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option>
                    <option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option>
                    <option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option>
                    <option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option>
                    <option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option>
                </select>
                :
                <select name="rows_ui[__INDEX__][start_m]" data-time-select>
                    <option value="">--</option>
                    <option value="00">00</option>
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="45">45</option>
                </select>
            </label>

            <label style="margin-right:12px;">
                終了
                <select name="rows_ui[__INDEX__][end_h]" data-time-select>
                    <option value="">--</option>
                    <option value="00">00</option><option value="01">01</option><option value="02">02</option><option value="03">03</option>
                    <option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option>
                    <option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option>
                    <option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option>
                    <option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option>
                    <option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option>
                </select>
                :
                <select name="rows_ui[__INDEX__][end_m]" data-time-select>
                    <option value="">--</option>
                    <option value="00">00</option>
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="45">45</option>
                </select>
            </label>

            <input type="hidden" name="rows[__INDEX__][start]" value="">
            <input type="hidden" name="rows[__INDEX__][end]" value="">

            <span class="task-duration">
                時間：<strong>--</strong> h
            </span>

            <button type="button" data-remove>削除</button>
        </div>
    </template>
    @vite('resources/js/timecalc.js')
    @endsection