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

    {{-- JS（行追加/削除 + index詰め + select→hidden(HH:MM)組み立て） --}}
    <script>
        (() => {
        const maxRows = 6;
        const MAX_HOURS_PER_ROW = 20;

        const rowsEl = document.getElementById('task-rows');
        const addBtn = document.getElementById('add-row');
        const tpl = document.getElementById('task-row-template');
        const form = document.getElementById('timecalc-form');

        // サマリー更新先
        const totalsEl = document.getElementById('category-totals');
        const totalHoursEl = document.getElementById('total-hours');
        const copyTextEl = document.getElementById('copy-text');

        // カテゴリの表示名（Controller側と合わせておく）
        const categoryLabels = {
            task1: 'タスク1',
            task2: 'タスク2',
            task3: 'タスク3',
        };

    function renumber() {
        const rows = rowsEl.querySelectorAll('[data-row]');
        rows.forEach((row, i) => {
        row.querySelectorAll('select, input').forEach(el => {
            el.name = el.name
            .replace(/rows\[\d+\]/, `rows[${i}]`)
            .replace(/rows_ui\[\d+\]/, `rows_ui[${i}]`);
        });
        });
    }

    function updateAddState() {
        const count = rowsEl.querySelectorAll('[data-row]').length;
        addBtn.disabled = count >= maxRows;
    }

    function parseHHMMToMinutes(hhmm) {
        if (!hhmm || !hhmm.includes(':')) return null;
        const [h, m] = hhmm.split(':').map(n => parseInt(n, 10));
        if (Number.isNaN(h) || Number.isNaN(m)) return null;
        return h * 60 + m;
    }

    function diffMinutesAllowOvernight(startHHMM, endHHMM) {
        const s = parseHHMMToMinutes(startHHMM);
        const e = parseHHMMToMinutes(endHHMM);
        if (s === null || e === null) return null;

        if (s === e) return 0; // 0時間（NG扱いする）
        let end = e;
        if (end < s) end += 24 * 60; // 夜間またぎ
        return end - s;
    }

    // UI select → hidden start/end(HH:MM) を組み立て
    function buildHiddenTimes() {
        const rows = rowsEl.querySelectorAll('[data-row]');
        rows.forEach((row, i) => {
        const sh = row.querySelector(`select[name="rows_ui[${i}][start_h]"]`)?.value || '';
        const sm = row.querySelector(`select[name="rows_ui[${i}][start_m]"]`)?.value || '';
        const eh = row.querySelector(`select[name="rows_ui[${i}][end_h]"]`)?.value || '';
        const em = row.querySelector(`select[name="rows_ui[${i}][end_m]"]`)?.value || '';

        const startHidden = row.querySelector(`input[name="rows[${i}][start]"]`);
        const endHidden   = row.querySelector(`input[name="rows[${i}][end]"]`);

        startHidden.value = (sh && sm) ? `${sh}:${sm}` : '';
        endHidden.value   = (eh && em) ? `${eh}:${em}` : '';
        });
    }

    function setRowHours(rowEl, hoursOrNull) {
        const strong = rowEl.querySelector('.task-duration strong');
        if (!strong) return;
        strong.textContent = (hoursOrNull === null) ? '--' : hoursOrNull.toFixed(2);
    }

    function setRowError(rowEl, message) {
        // 行エラー表示用（存在しなければ作る）
        let err = rowEl.querySelector('[data-row-error]');
        if (!message) {
        if (err) err.remove();
        rowEl.style.borderColor = '#ddd';
        return;
        }
        if (!err) {
        err = document.createElement('div');
        err.setAttribute('data-row-error', '1');
        err.style.marginTop = '6px';
        err.style.color = '#b00020';
        err.style.fontSize = '0.9em';
        rowEl.appendChild(err);
        }
        err.textContent = message;
        rowEl.style.borderColor = '#f00';
    }

    function updateRealtimeCalculation() {
        buildHiddenTimes();

        const rows = rowsEl.querySelectorAll('[data-row]');
        const categoryTotals = {};
        let totalHours = 0;

        rows.forEach((rowEl, i) => {
        const category = rowEl.querySelector(`select[name="rows[${i}][category]"]`)?.value || '';
        const start = rowEl.querySelector(`input[name="rows[${i}][start]"]`)?.value || '';
        const end   = rowEl.querySelector(`input[name="rows[${i}][end]"]`)?.value || '';

        // 何も入力されてない行は無視
        const hasAny = category || start || end;

        if (!hasAny) {
            setRowHours(rowEl, null);
            setRowError(rowEl, '');
            return;
        }

        // 片方だけ入力（start/end）などを即エラー
        if (!category) {
            setRowHours(rowEl, null);
            setRowError(rowEl, 'カテゴリを選択してください。');
            return;
        }
        if (!start || !end) {
            setRowHours(rowEl, null);
            setRowError(rowEl, '開始・終了の時刻を両方選択してください。');
            return;
        }

        const diffMin = diffMinutesAllowOvernight(start, end);

        if (diffMin === 0) {
            setRowHours(rowEl, null);
            setRowError(rowEl, '0時間（開始＝終了）は不可です。');
            return;
        }
        if (diffMin === null) {
            setRowHours(rowEl, null);
            setRowError(rowEl, '時刻の形式が不正です。');
            return;
        }
        if (diffMin > MAX_HOURS_PER_ROW * 60) {
            setRowHours(rowEl, null);
            setRowError(rowEl, `勤務時間が長すぎます（${MAX_HOURS_PER_ROW}時間以内）。`);
            return;
        }

        // 15分刻みはUIで固定だが、念のため
        if (diffMin % 15 !== 0) {
            setRowHours(rowEl, null);
            setRowError(rowEl, '15分刻みで入力してください。');
            return;
        }

        const hours = (diffMin / 15) * 0.25;
        setRowHours(rowEl, hours);
        setRowError(rowEl, '');

        categoryTotals[category] = (categoryTotals[category] || 0) + hours;
        totalHours += hours;
        });

        // サマリー更新
        if (totalsEl) totalsEl.innerHTML = '';
        const lines = [];

        Object.keys(categoryTotals).forEach(cat => {
        const label = categoryLabels[cat] || cat;
        const h = categoryTotals[cat];
        lines.push(`${label}：${h.toFixed(2)}`);

        if (totalsEl) {
            const li = document.createElement('li');
            li.textContent = `${label}：${h.toFixed(2)} h`;
            totalsEl.appendChild(li);
        }
        });

        if (totalHoursEl) totalHoursEl.textContent = (totalHours ? totalHours.toFixed(2) : '--');

        lines.push(`合計：${totalHours.toFixed(2)}`);
        if (copyTextEl) copyTextEl.value = (Object.keys(categoryTotals).length ? lines.join('\n') : '');
    }

    addBtn.addEventListener('click', () => {
        const count = rowsEl.querySelectorAll('[data-row]').length;
        if (count >= maxRows) return;

        const html = tpl.innerHTML.replaceAll('__INDEX__', count);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        rowsEl.appendChild(wrapper.firstChild);

        renumber();
        updateAddState();
        updateRealtimeCalculation();
    });

    rowsEl.addEventListener('click', (e) => {
        if (!e.target.matches('[data-remove]')) return;

        const rows = rowsEl.querySelectorAll('[data-row]');
        if (rows.length <= 1) return;

        e.target.closest('[data-row]').remove();
        renumber();
        updateAddState();
        updateRealtimeCalculation();
    });

    // 入力が変わるたびに即時計算
    rowsEl.addEventListener('change', (e) => {
        if (e.target.matches('select')) {
        updateRealtimeCalculation();
        }
    });

    // 送信直前も同期（ハイブリッドの保険）
    form.addEventListener('submit', () => {
        updateRealtimeCalculation();
    });

    updateAddState();
    updateRealtimeCalculation();
    })();
</script>
@endsection