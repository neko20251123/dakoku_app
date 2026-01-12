@if ($errors->any())
    <div style="padding: 8px; border: 1px solid #f00; margin: 12px 0;">
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<p class="note">
    ※ 時刻の入力ルール  
    <br>
    ・終了時刻が開始時刻より前の場合は、翌日として計算します。  
    <br>
    ・0時間（開始＝終了）は入力できません。  
    <br>
    ・1行あたりの作業時間は <strong>最大20時間まで</strong> です（入力ミス防止のため）。
</p>

<form method="POST" action="{{ route('timecalc.calculate') }}">
    @csrf

    <div class="task-rows">

        {{-- 1行目だけ --}}
        <div class="task-row">
            <label>
                カテゴリ
                <select name="rows[0][category]">
                    <option value="">選択してください</option>
                    <option value="task1" @selected(old('rows.0.category') === 'task1')>タスク1（仮）</option>
                    <option value="task2" @selected(old('rows.0.category') === 'task2')>タスク2（仮）</option>
                    <option value="task3" @selected(old('rows.0.category') === 'task3')>タスク3（仮）</option>
                </select>

            </label>
            
            <label>
                開始
                <input type="time" name="rows[0][start]" value="{{ old('rows.0.start') }}">
            </label>
            
            <label>
                終了
                <input type="time" name="rows[0][end]"   value="{{ old('rows.0.end') }}">
            </label>

            <span class="task-duration">
                時間：<span>--</span> h
            </span>
        </div>

    </div>

    <button type="button">＋ 行を追加</button>

    <div class="actions">
        <button type="submit">計算する</button>
    </div>
</form>