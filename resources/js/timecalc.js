// resources/js/timecalc.js
(() => {
  const rowsEl = document.getElementById('task-rows');
  const addBtn = document.getElementById('add-row');
  const tpl = document.getElementById('task-row-template');
  const form = document.getElementById('timecalc-form');

  // このページ以外で読み込まれても落ちないようにガード
  if (!rowsEl || !addBtn || !tpl || !form) return;

  // ↓ここから下に、今のJS本体を貼る（rowsEl/addBtn/tpl/form の再定義は不要）
})();

// {{-- JS（行追加/削除 + index詰め + select→hidden(HH:MM)組み立て） --}}
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
