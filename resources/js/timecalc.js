// resources/js/timecalc.js
(() => {
  // ページ外で読まれても落ちないように、必要なDOMが無ければ何もしない
  const rowsEl = document.getElementById('task-rows');
  const addBtn = document.getElementById('add-row');
  const tpl = document.getElementById('task-row-template');
  const form = document.getElementById('timecalc-form');
  const clearAllBtn = document.getElementById('clear-all');

  if (!rowsEl || !addBtn || !tpl || !form) return;

  // 要件設定
  const maxRows = 6;              // 最大行数
  const MAX_HOURS_PER_ROW = 20;   // 1行あたり最大時間（事故防止）
  const DEFAULT_START_H = '09';   // 開始時刻デフォルト（時）
  const DEFAULT_START_M = '00';   // 開始時刻デフォルト（分）
  const AUTO_END_ADD_MINUTES = 15; // 自動補完する終了時刻（開始+○分）

  // サマリー描画先
  const totalsEl = document.getElementById('category-totals');
  const totalHoursEl = document.getElementById('total-hours');

  // カテゴリ文字列の表記ゆれを減らす（前後空白・連続スペース対策）
  function normalizeCategory(str) {
    return (str || '').trim().replace(/\s+/g, ' ');
  }

  // 行追加・削除後に name の index を詰め直す（rows / rows_ui の0..nを揃える）
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

  // 現在行数に応じて「行を追加」ボタンをdisableする
  function updateAddState() {
    const count = rowsEl.querySelectorAll('[data-row]').length;
    addBtn.disabled = count >= maxRows;
  }

  // "HH:MM" を分に変換する（例: "09:15" -> 555）
  function parseHHMMToMinutes(hhmm) {
    if (!hhmm || !hhmm.includes(':')) return null;
    const [h, m] = hhmm.split(':').map(n => parseInt(n, 10));
    if (Number.isNaN(h) || Number.isNaN(m)) return null;
    return h * 60 + m;
  }

  // 開始・終了の差分（分）を計算（夜間またぎ対応）
  function diffMinutesAllowOvernight(startHHMM, endHHMM) {
    const s = parseHHMMToMinutes(startHHMM);
    const e = parseHHMMToMinutes(endHHMM);
    if (s === null || e === null) return null;

    if (s === e) return 0;

    let end = e;
    if (end < s) end += 24 * 60; // 夜間またぎ
    return end - s;
  }

  // select（rows_ui：時/分）から hidden（rows[start/end]：HH:MM）へ同期する
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

  // 行の「時間」表示を更新する（.task-duration strong）
  function setRowHours(rowEl, hoursOrNull) {
    const strong = rowEl.querySelector('.task-duration strong');
    if (!strong) return;
    strong.textContent = (hoursOrNull === null) ? '--' : hoursOrNull.toFixed(2);
  }

  // 行エラーを表示・解除する（messageが空なら解除）
  function setRowError(rowEl, message) {
    let err = rowEl.querySelector('[data-row-error]');

    if (!message) {
      if (err) err.remove();
      rowEl.style.borderColor = '';
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

  // サマリー上のメッセージ（成功/失敗）を表示する
  function showSummaryMessage(type, text) {
    const el = document.getElementById('summary-message');
    if (!el) return;

    el.textContent = text;
    el.classList.remove('hidden');

    el.className =
      'mb-4 rounded-lg border px-4 py-3 text-sm ' +
      (type === 'success'
        ? 'border-green-200 bg-green-50 text-green-900'
        : 'border-red-200 bg-red-50 text-red-900');
  }

  // サマリー上のメッセージを消す
  function hideSummaryMessage() {
    const el = document.getElementById('summary-message');
    if (!el) return;

    el.classList.add('hidden');
    el.textContent = '';
    el.className = 'mb-4 hidden rounded-lg border px-4 py-3 text-sm';
  }

  // 合計カード内の「一言」メッセージを表示する
  function showToastMessage(text) {
    const el = document.getElementById('toast-message');
    if (!el) return;

    el.textContent = text;
    el.classList.remove('hidden');
  }

  // 合計カード内の「一言」メッセージを消す
  function hideToastMessage() {
    const el = document.getElementById('toast-message');
    if (!el) return;

    el.classList.add('hidden');
    el.textContent = '';
  }

  // "HH" と "MM" を合計分にして、指定分だけ加算した結果を返す（24時間またぎ対応）
  // 例: 23:45 + 15分 -> 00:00
  function addMinutesToTime(hh, mm, addMin) {
    const h = parseInt(hh, 10);
    const m = parseInt(mm, 10);
    if (Number.isNaN(h) || Number.isNaN(m)) return null;

    // いったん「分」にして加算し、0..1439 の範囲に正規化する
    let total = h * 60 + m + addMin;
    total = ((total % (24 * 60)) + (24 * 60)) % (24 * 60);

    const nh = String(Math.floor(total / 60)).padStart(2, '0');
    const nm = String(total % 60).padStart(2, '0');
    return { hh: nh, mm: nm };
  }

  // 「ユーザーが終了を手で触ったか」を判定するフラグを扱う
  // 方針:
  // - 終了（時 or 分）を変更したら、その行は追従停止（上書きしない）
  // - 行クリア/全クリア/行追加時は追従を復活させる（毎日使いの快適さ優先）
  function lockEndTime(rowEl) {
    rowEl.dataset.endLocked = '1';
  }

  function unlockEndTime(rowEl) {
    delete rowEl.dataset.endLocked;
  }

  function isEndTimeLocked(rowEl) {
    return rowEl.dataset.endLocked === '1';
  }

  // 終了を「開始 + 15分」に自動セットする（追従用）
  // 方針:
  // - 開始が未入力（時または分が空）の場合は何もしない
  // - 終了がロックされている行は、ユーザー入力を尊重して上書きしない
  // - 0時間禁止を自然に回避するため、開始と同じ時刻ではなく +15分 にする
  // - 終了が途中入力（時だけ/分だけ）の場合も、勝手に完成させない（ユーザー操作を優先）
  function setEndTimeAutoFollow(rowEl) {
    // ロックされている行は絶対に上書きしない
    if (isEndTimeLocked(rowEl)) return;

    const shEl = rowEl.querySelector('select[name$="[start_h]"]');
    const smEl = rowEl.querySelector('select[name$="[start_m]"]');
    const ehEl = rowEl.querySelector('select[name$="[end_h]"]');
    const emEl = rowEl.querySelector('select[name$="[end_m]"]');

    if (!shEl || !smEl || !ehEl || !emEl) return;

    const sh = shEl.value || '';
    const sm = smEl.value || '';

    // 開始が揃っていないなら、終了は決められないので何もしない
    if (!sh || !sm) return;

    // 終了が「途中入力」の場合は、ユーザーが操作中なので上書きしない
    const eh = ehEl.value || '';
    const em = emEl.value || '';
    if ((eh && !em) || (!eh && em)) return;

    // 開始+15分を終了にセット
    const next = addMinutesToTime(sh, sm, AUTO_END_ADD_MINUTES);
    if (!next) return;

    ehEl.value = next.hh;
    emEl.value = next.mm;
  }

  // 行の開始時刻をデフォルト 09:00 にする（未選択の場合のみ）
  // あわせて、終了は「追従ON」に戻して、開始+15分を自動セットする（スクロール地獄回避）
  function setDefaultStartTime(rowEl) {
    const sh = rowEl.querySelector('select[name$="[start_h]"]');
    const sm = rowEl.querySelector('select[name$="[start_m]"]');

    if (sh && !sh.value) sh.value = DEFAULT_START_H;
    if (sm && !sm.value) sm.value = DEFAULT_START_M;

    // デフォルトセット時は「追従ON」に戻す（毎日使いで迷いが出ないように）
    unlockEndTime(rowEl);

    // 終了を開始+15分へ追従セット
    setEndTimeAutoFollow(rowEl);
  }

  // 入力内容を元に即時計算してUIへ反映する
  function updateRealtimeCalculation() {
    // hidden（サーバ送信用）を最新化
    buildHiddenTimes();

    const rows = rowsEl.querySelectorAll('[data-row]');
    const categoryTotals = {};
    let totalHours = 0;

    rows.forEach((rowEl, i) => {
      const categoryRaw = rowEl.querySelector(`input[name="rows[${i}][category]"]`)?.value || '';
      const category = normalizeCategory(categoryRaw);

      const start = rowEl.querySelector(`input[name="rows[${i}][start]"]`)?.value || '';
      const end   = rowEl.querySelector(`input[name="rows[${i}][end]"]`)?.value || '';

      // 行が完全に空なら無視
      const hasAny = category || start || end;
      if (!hasAny) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '');
        return;
      }

      // 入力漏れ
      if (!category) {
        setRowHours(rowEl, null);
        setRowError(rowEl, 'カテゴリを入力してください。');
        return;
      }
      if (!start || !end) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '開始・終了の時刻を両方選択してください。');
        return;
      }

      // 差分（分）計算（夜間またぎ対応）
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
      if (diffMin % 15 !== 0) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '15分刻みで入力してください。');
        return;
      }

      // 15分 = 0.25h で換算
      const hours = (diffMin / 15) * 0.25;

      setRowHours(rowEl, hours);
      setRowError(rowEl, '');

      categoryTotals[category] = (categoryTotals[category] || 0) + hours;
      totalHours += hours;
    });

    // カテゴリ別合計の描画
    if (totalsEl) totalsEl.innerHTML = '';
    Object.keys(categoryTotals).forEach(cat => {
      const li = document.createElement('li');
      li.textContent = `${cat}：${categoryTotals[cat].toFixed(2)} h`;
      totalsEl?.appendChild(li);
    });

    // 合計時間の描画
    if (totalHoursEl) {
      totalHoursEl.textContent = totalHours ? totalHours.toFixed(2) : '--';
    }
  }

  // 行追加：テンプレから複製して追加
  addBtn.addEventListener('click', () => {
    const count = rowsEl.querySelectorAll('[data-row]').length;
    if (count >= maxRows) return;

    const html = tpl.innerHTML.replaceAll('__INDEX__', count);
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html.trim();

    const newRow = wrapper.firstChild;
    rowsEl.appendChild(newRow);

    // 追加行の開始はデフォルト 09:00（終了も開始+15分へ追従セット）
    setDefaultStartTime(newRow);

    renumber();
    updateAddState();
    updateRealtimeCalculation();
  });

  // 行クリア・行削除（イベント委譲）
  rowsEl.addEventListener('click', (e) => {
    if (e.target.matches('[data-clear-row]')) {
      const rowEl = e.target.closest('[data-row]');
      if (!rowEl) return;

      // 行内の値をクリア
      const catInput = rowEl.querySelector('input[name$="[category]"]');
      if (catInput) catInput.value = '';

      rowEl.querySelectorAll('select[data-time-select]').forEach(s => (s.value = ''));
      rowEl.querySelectorAll('input[type="hidden"]').forEach(h => (h.value = ''));

      // クリア後は追従ONに戻して、開始を 09:00、終了を開始+15分へ
      setDefaultStartTime(rowEl);

      setRowHours(rowEl, null);
      setRowError(rowEl, '');

      updateRealtimeCalculation();
      return;
    }

    if (e.target.matches('[data-remove]')) {
      const rows = rowsEl.querySelectorAll('[data-row]');
      if (rows.length <= 1) return;

      e.target.closest('[data-row]').remove();

      renumber();
      updateAddState();
      updateRealtimeCalculation();
    }
  });

  // select変更で即時計算
  // 追加仕様:
  // - 終了（時/分）を変更したら、その行は「追従停止（ロック）」する
  // - 開始（時/分）を変更したら、終了は「追従ONの行だけ」開始+15分へ追従させる
  rowsEl.addEventListener('change', (e) => {
    if (!e.target.matches('select')) return;

    const rowEl = e.target.closest('[data-row]');
    if (!rowEl) {
      updateRealtimeCalculation();
      return;
    }

    // 終了をユーザーが触ったら、その行は追従停止（上書きしない）
    if (e.target.name?.endsWith('[end_h]') || e.target.name?.endsWith('[end_m]')) {
      lockEndTime(rowEl);
      updateRealtimeCalculation();
      return;
    }

    // 開始が変わった場合のみ、追従ONの行は終了を開始+15分に追従
    if (e.target.name?.endsWith('[start_h]') || e.target.name?.endsWith('[start_m]')) {
      setEndTimeAutoFollow(rowEl);
      updateRealtimeCalculation();
      return;
    }

    updateRealtimeCalculation();
  });

  // カテゴリ入力で即時計算
  rowsEl.addEventListener('input', (e) => {
    if (e.target.matches('input[name^="rows["][name$="[category]"]')) {
      updateRealtimeCalculation();
    }
  });

  // 全クリア：行は残して値だけ消す
  if (clearAllBtn) {
    clearAllBtn.addEventListener('click', () => {
      if (!confirm('すべての入力内容をクリアします。よろしいですか？')) return;

      rowsEl.querySelectorAll('[data-row]').forEach(rowEl => {
        rowEl.querySelectorAll('input, select').forEach(el => (el.value = ''));
        setRowHours(rowEl, null);
        setRowError(rowEl, '');

        // クリア後も追従ONに戻して、開始は09:00、終了は開始+15分へ
        setDefaultStartTime(rowEl);
      });

      updateAddState();
      updateRealtimeCalculation();

      // 成功/失敗メッセージと一言を消す
      hideSummaryMessage();
      hideToastMessage();

      const firstCat = rowsEl.querySelector('input[name$="[category]"]');
      if (firstCat) firstCat.focus();
    });
  }

  // 非同期送信：サーバを正として最終チェック
  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    // 送信直前にhiddenを最新化
    updateRealtimeCalculation();

    const formData = new FormData(form);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    try {
      const res = await fetch('/calculate-json', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrf ?? '',
          'Accept': 'application/json',
        },
        body: formData,
      });

      const data = await res.json();

      // 失敗（422など）
      if (!res.ok) {
        const rowEls = rowsEl.querySelectorAll('[data-row]');
        rowEls.forEach(r => setRowError(r, ''));

        if (data?.errors) {
          Object.entries(data.errors).forEach(([key, messages]) => {
            const match = key.match(/^rows\.(\d+)\./);
            if (!match) return;

            const idx = parseInt(match[1], 10);
            const rowEl = rowEls[idx];
            if (rowEl) setRowError(rowEl, messages[0]);
          });
        }

        // 失敗表示はサマリー上に統一
        showSummaryMessage('error', '入力エラーがあります。赤い行を確認してください。');
        hideToastMessage();
        return;
      }

      // 成功表示はサマリー上に統一
      showSummaryMessage('success', '計算OK！内容を確認してください。');
      if (data.toast) showToastMessage(data.toast);
    } catch (err) {
      console.error(err);
      showSummaryMessage('error', '通信エラーが発生しました。');
      hideToastMessage();
    }
  });

  // 初期表示：行数状態を整えて、最初の行の開始を 09:00 にする
  updateAddState();

  const firstRow = rowsEl.querySelector('[data-row]');
  if (firstRow) setDefaultStartTime(firstRow);

  updateRealtimeCalculation();
})();