// resources/js/timecalc.js
(() => {
  /**
   * ===== このJSの役割 =====
   * ・行の追加/削除（最大行数あり）
   * ・時/分 select（rows_ui）から hidden（rows[start/end]）へ HH:MM を組み立て
   * ・入力のたびに「行の時間」「カテゴリ別合計」「合計時間」を即時計算して表示
   *
   * ※ サーバ側でも同じルールで最終チェックする（ダブルチェック構成）
   */

  // ---- DOM参照（このページ以外で読み込まれても落ちないようにガードする） ----
  const rowsEl = document.getElementById('task-rows');
  const addBtn = document.getElementById('add-row');
  const tpl = document.getElementById('task-row-template');
  const form = document.getElementById('timecalc-form');
  const clearAllBtn = document.getElementById('clear-all');

  if (!rowsEl || !addBtn || !tpl || !form) return;

  // ---- 設定値（要件） ----
  const maxRows = 6;
  const MAX_HOURS_PER_ROW = 20;

  // ---- サマリー表示先 ----
  const totalsEl = document.getElementById('category-totals');
  const totalHoursEl = document.getElementById('total-hours');

  /**
   * カテゴリ文字列の正規化（表記ゆれ対策）
   * - 前後空白を削除
   * - 連続スペースを1つに
   */
  function normalizeCategory(str) {
    return (str || '').trim().replace(/\s+/g, ' ');
  }

  /**
   * 行追加/削除後に name の index を詰め直す
   * - rows[0]..rows[n]
   * - rows_ui[0]..rows_ui[n]
   */
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

  /**
   * 行追加ボタンを最大行数でdisable
   */
  function updateAddState() {
    const count = rowsEl.querySelectorAll('[data-row]').length;
    addBtn.disabled = count >= maxRows;
  }

  /**
   * "HH:MM" → 分（int）に変換
   * 例: "09:15" → 555
   */
  function parseHHMMToMinutes(hhmm) {
    if (!hhmm || !hhmm.includes(':')) return null;
    const [h, m] = hhmm.split(':').map(n => parseInt(n, 10));
    if (Number.isNaN(h) || Number.isNaN(m)) return null;
    return h * 60 + m;
  }

  /**
   * 差分（分）を計算（夜間またぎ対応）
   * - end < start の場合は翌日扱い（+24h）
   * - start == end は 0 を返す（0時間NG判定に使う）
   */
  function diffMinutesAllowOvernight(startHHMM, endHHMM) {
    const s = parseHHMMToMinutes(startHHMM);
    const e = parseHHMMToMinutes(endHHMM);
    if (s === null || e === null) return null;

    if (s === e) return 0; // 0時間
    let end = e;
    if (end < s) end += 24 * 60; // 夜間またぎ
    return end - s;
  }

  /**
   * UI select（rows_ui）→ hidden（rows[start/end]）へ "HH:MM" を組み立てて入れる
   * - 片方だけ選ばれている場合は空文字にする（サーバのrequiredで弾ける）
   */
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

  /**
   * 行の「時間」表示を更新
   */
  function setRowHours(rowEl, hoursOrNull) {
    const strong = rowEl.querySelector('.task-duration strong');
    if (!strong) return;
    strong.textContent = (hoursOrNull === null) ? '--' : hoursOrNull.toFixed(2);
  }

  /**
   * 行のエラー表示を更新（なければ要素を作る）
   */
  function setRowError(rowEl, message) {
    let err = rowEl.querySelector('[data-row-error]');

    // エラー解除
    if (!message) {
      if (err) err.remove();
      rowEl.style.borderColor = '#ddd';
      return;
    }

    // エラー要素がなければ作成
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

  /**
   * 即時計算の本体
   * - 各行の状態を見て「時間」「エラー」を更新
   * - カテゴリ別合計、合計時間を更新
   */
  function updateRealtimeCalculation() {
    // まず hidden start/end を最新にする
    buildHiddenTimes();

    const rows = rowsEl.querySelectorAll('[data-row]');
    const categoryTotals = {};
    let totalHours = 0;

    rows.forEach((rowEl, i) => {
      // カテゴリは自由入力（input）前提
      const categoryRaw = rowEl.querySelector(`input[name="rows[${i}][category]"]`)?.value || '';
      const category = normalizeCategory(categoryRaw);

      // hiddenに入っている "HH:MM"
      const start = rowEl.querySelector(`input[name="rows[${i}][start]"]`)?.value || '';
      const end   = rowEl.querySelector(`input[name="rows[${i}][end]"]`)?.value || '';

      // 何も入力されていない行は無視
      const hasAny = category || start || end;
      if (!hasAny) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '');
        return;
      }

      // 入力途中や漏れは即エラー表示（サーバでも同様に弾く）
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

      const diffMin = diffMinutesAllowOvernight(start, end);

      // 0時間
      if (diffMin === 0) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '0時間（開始＝終了）は不可です。');
        return;
      }

      // 時刻フォーマット不正（理論上は出にくいが保険）
      if (diffMin === null) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '時刻の形式が不正です。');
        return;
      }

      // 1行あたり最大時間
      if (diffMin > MAX_HOURS_PER_ROW * 60) {
        setRowHours(rowEl, null);
        setRowError(rowEl, `勤務時間が長すぎます（${MAX_HOURS_PER_ROW}時間以内）。`);
        return;
      }

      // 15分刻み（UIで固定だが保険）
      if (diffMin % 15 !== 0) {
        setRowHours(rowEl, null);
        setRowError(rowEl, '15分刻みで入力してください。');
        return;
      }

      // 0.25h単位に換算（15分 = 0.25h）
      const hours = (diffMin / 15) * 0.25;

      // 行の表示を更新
      setRowHours(rowEl, hours);
      setRowError(rowEl, '');

      // 集計（カテゴリ別）
      categoryTotals[category] = (categoryTotals[category] || 0) + hours;
      totalHours += hours;
    });

    // ---- サマリー更新（カテゴリ別合計） ----
    if (totalsEl) totalsEl.innerHTML = '';

    Object.keys(categoryTotals).forEach(cat => {
      const h = categoryTotals[cat];

      if (totalsEl) {
        const li = document.createElement('li');
        li.textContent = `${cat}：${h.toFixed(2)} h`;
        totalsEl.appendChild(li);
      }
    });

    // ---- サマリー更新（合計） ----
    if (totalHoursEl) {
      totalHoursEl.textContent = (totalHours ? totalHours.toFixed(2) : '--');
    }
  }

  /**
   * 行追加（テンプレを追加して、index詰め直し → 即時計算）
   */
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

  /**
   * 行クリア / 行削除（イベント委譲）
   */
  rowsEl.addEventListener('click', (e) => {
    // 行クリア
    if (e.target.matches('[data-clear-row]')) {
      const rowEl = e.target.closest('[data-row]');
      if (!rowEl) return;

      // カテゴリ（テキスト）
      const catInput = rowEl.querySelector('input[name$="[category]"]');
      if (catInput) catInput.value = '';

      // 時・分（select）
      rowEl.querySelectorAll('select[data-time-select]').forEach(s => (s.value = ''));

      // hidden start/end
      const hiddenStart = rowEl.querySelector('input[type="hidden"][name$="[start]"]');
      const hiddenEnd   = rowEl.querySelector('input[type="hidden"][name$="[end]"]');
      if (hiddenStart) hiddenStart.value = '';
      if (hiddenEnd) hiddenEnd.value = '';

      // 表示・エラーをリセット
      setRowHours(rowEl, null);
      setRowError(rowEl, '');

      updateRealtimeCalculation();
      return;
    }

    // 行削除（最低1行は残す）
    if (e.target.matches('[data-remove]')) {
      const rows = rowsEl.querySelectorAll('[data-row]');
      if (rows.length <= 1) return;

      e.target.closest('[data-row]').remove();
      renumber();
      updateAddState();
      updateRealtimeCalculation();
    }
  });

  /**
   * 入力が変わるたびに即時計算
   * - select（時/分）変更
   * - input（カテゴリ）入力
   */
  rowsEl.addEventListener('change', (e) => {
    if (e.target.matches('select')) {
      updateRealtimeCalculation();
    }
  });

  rowsEl.addEventListener('input', (e) => {
    if (e.target.matches('input[name^="rows["][name$="[category]"]')) {
      updateRealtimeCalculation();
    }
  });

  /**
   * 送信直前にも同期（サーバ最終チェックの前に hidden を最新化）
   */
  form.addEventListener('submit', () => {
    updateRealtimeCalculation();
  });

  /**
   * 全クリア（行は残して入力だけクリア）
   */
 if (clearAllBtn) {
  clearAllBtn.addEventListener('click', () => {
    // 確認モーダル
    if (!confirm('すべての入力内容をクリアします。よろしいですか？')) {
      return;
    }

    // ★行は削除しない。各行の入力値だけクリアする
    const rows = rowsEl.querySelectorAll('[data-row]');
    rows.forEach((rowEl) => {
      // カテゴリ（テキスト）
      const catInput = rowEl.querySelector('input[name$="[category]"]');
      if (catInput) catInput.value = '';

      // 時・分（select）
      rowEl.querySelectorAll('select[data-time-select]').forEach(s => (s.value = ''));

      // hidden start/end
      const hiddenStart = rowEl.querySelector('input[type="hidden"][name$="[start]"]');
      const hiddenEnd   = rowEl.querySelector('input[type="hidden"][name$="[end]"]');
      if (hiddenStart) hiddenStart.value = '';
      if (hiddenEnd) hiddenEnd.value = '';

      // 行表示・エラーをリセット
      setRowHours(rowEl, null);
      setRowError(rowEl, '');
    });

    updateAddState();
    updateRealtimeCalculation();
    const firstCat = rowsEl.querySelector('input[name$="[category]"]');
    if (firstCat) firstCat.focus();

    const toast = document.getElementById('toast-message');
    if (toast) toast.remove();
  });
}

  // 初期化
  updateAddState();
  updateRealtimeCalculation();
})();