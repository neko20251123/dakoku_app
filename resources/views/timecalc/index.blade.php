{{-- resources/views/timecalc/index.blade.php --}}
@extends('layouts.app')

@section('title', '打刻計算ツール')

@section('content')
@php
  // old優先（バリデーションで戻ってきたときに行数も復元したい）
  $rows = old('rows') ?? [ ['category' => '', 'start' => '', 'end' => ''] ];
  $result = session('result');
  $minutesOptions = ['00', '15', '30', '45'];
@endphp

<div class="mx-auto max-w-5xl p-6 space-y-6">
  {{-- Header --}}
  <div class="flex items-start justify-between gap-4">
    <div>
      <h1 class="text-2xl font-bold tracking-tight">🕒 打刻計算ツール</h1>
      <p class="mt-1 text-sm text-slate-600">
        カテゴリと時間を入力して、打刻用の時間（0.25h単位）を自動計算します。
      </p>
    </div>
    <div class="hidden sm:block text-xs text-slate-500">
      DBなし / 即時計算 + サーバ最終チェック
    </div>
  </div>

  {{-- Errors --}}
  @if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
      <div class="font-semibold mb-2">入力エラー</div>
      <ul class="list-disc pl-5 space-y-1 text-sm">
        @foreach ($errors->all() as $message)
          <li>{{ $message }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  {{-- Rules --}}
  <details class="group rounded-xl border bg-white p-4 shadow-sm" open>
    <summary class="cursor-pointer list-none">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-slate-100">ℹ️</span>
          <span class="font-semibold text-slate-900">入力ルール</span>
        </div>
        <span class="text-sm text-slate-500 group-open:hidden">開く</span>
        <span class="text-sm text-slate-500 hidden group-open:inline">閉じる</span>
      </div>
    </summary>

    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-slate-700">
      <div class="rounded-lg bg-slate-50 p-3">
        <div class="font-medium mb-1 text-slate-900">時刻</div>
        <ul class="list-disc pl-5 space-y-1">
          <li>15分刻み（00/15/30/45）</li>
          <li>終了が開始より前なら翌日扱い</li>
          <li>0時間（開始＝終了）は不可</li>
        </ul>
      </div>
      <div class="rounded-lg bg-slate-50 p-3">
        <div class="font-medium mb-1 text-slate-900">安全装置</div>
        <ul class="list-disc pl-5 space-y-1">
          <li>1行あたり最大20時間まで（入力ミス防止）</li>
          <li>JS即時計算 + サーバ最終チェック</li>
        </ul>
      </div>
    </div>
  </details>

  {{-- Category datalist --}}
  <datalist id="category-options">
    <option value="開発">
    <option value="調査">
    <option value="保守">
    <option value="会議">
  </datalist>

  {{-- Main card --}}
  <div class="rounded-2xl border bg-white shadow-sm">
    <div class="border-b px-5 py-4 flex items-center justify-between">
      <div class="font-semibold text-slate-900">入力</div>
      <div class="text-xs text-slate-500">行は最大6 / クリア・削除あり</div>
    </div>

    <form id="timecalc-form" method="POST" action="{{ route('timecalc.calculate') }}" class="p-5 space-y-4">
      @csrf

      {{-- Rows --}}
      <div id="task-rows" class="space-y-3">
        @foreach ($rows as $i => $row)
          @php
            $start = $row['start'] ?? '';
            $end   = $row['end'] ?? '';
            [$sh, $sm] = ($start && str_contains($start, ':')) ? explode(':', $start) : ['', ''];
            [$eh, $em] = ($end && str_contains($end, ':')) ? explode(':', $end) : ['', ''];
          @endphp

          <div data-row class="rounded-xl border bg-slate-50 p-4">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
              {{-- Category --}}
              <div class="md:col-span-4">
                <label class="block text-xs font-medium text-slate-600">カテゴリ（自由入力OK）</label>
                <input
                  type="text"
                  name="rows[{{ $i }}][category]"
                  list="category-options"
                  value="{{ $row['category'] ?? '' }}"
                  placeholder="例：開発 / 調査 / 保守 / 会議"
                  class="mt-1 w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
                >
              </div>

              {{-- Start --}}
              <div class="md:col-span-3">
                <label class="block text-xs font-medium text-slate-600">開始</label>
                <div class="mt-1 flex items-center gap-2">
                  <select name="rows_ui[{{ $i }}][start_h]" data-time-select
                    class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                    <option value="">--</option>
                    @for ($h = 0; $h <= 23; $h++)
                      @php $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT); @endphp
                      <option value="{{ $hh }}" @selected($sh === $hh)>{{ $hh }}</option>
                    @endfor
                  </select>
                  <span class="text-slate-400">:</span>
                  <select name="rows_ui[{{ $i }}][start_m]" data-time-select
                    class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                    <option value="">--</option>
                    @foreach ($minutesOptions as $mm)
                      <option value="{{ $mm }}" @selected($sm === $mm)>{{ $mm }}</option>
                    @endforeach
                  </select>
                </div>
              </div>

              {{-- End --}}
              <div class="md:col-span-3">
                <label class="block text-xs font-medium text-slate-600">終了</label>
                <div class="mt-1 flex items-center gap-2">
                  <select name="rows_ui[{{ $i }}][end_h]" data-time-select
                    class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                    <option value="">--</option>
                    @for ($h = 0; $h <= 23; $h++)
                      @php $hh = str_pad((string)$h, 2, '0', STR_PAD_LEFT); @endphp
                      <option value="{{ $hh }}" @selected($eh === $hh)>{{ $hh }}</option>
                    @endfor
                  </select>
                  <span class="text-slate-400">:</span>
                  <select name="rows_ui[{{ $i }}][end_m]" data-time-select
                    class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                    <option value="">--</option>
                    @foreach ($minutesOptions as $mm)
                      <option value="{{ $mm }}" @selected($em === $mm)>{{ $mm }}</option>
                    @endforeach
                  </select>
                </div>
              </div>

              {{-- Hidden (server compatibility) --}}
              <input type="hidden" name="rows[{{ $i }}][start]" value="{{ $start }}">
              <input type="hidden" name="rows[{{ $i }}][end]"   value="{{ $end }}">

              {{-- Hours --}}
              <div class="md:col-span-1 text-center">
                <div class="text-xs text-slate-500">時間</div>
                <div class="mt-1 text-lg font-bold text-slate-900 task-duration">
                  <strong>{{ isset($result['rowHours'][$i]) ? number_format($result['rowHours'][$i], 2) : '--' }}</strong>
                  <span class="text-sm font-semibold text-slate-500">h</span>
                </div>
              </div>

              {{-- Actions --}}
              <div class="md:col-span-1 flex md:flex-col gap-2 justify-end">
                <button type="button" data-clear-row
                  class="rounded-lg bg-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-300">
                  クリア
                </button>
                <button type="button" data-remove
                  class="rounded-lg bg-red-100 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-200">
                  削除
                </button>
              </div>
            </div>
          </div>
        @endforeach
      </div>

      {{-- Toolbar --}}
      <div class="flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between pt-2">
        <div class="flex flex-wrap gap-2">
          <button type="button" id="add-row"
            class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            ＋ 行を追加
          </button>

          <button type="button" id="clear-all"
            class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
            全クリア
          </button>
        </div>

        <button type="submit"
          class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700">
          計算する（最終チェック）
        </button>
      </div>
    </form>
  </div>

  {{-- Summary --}}
  <div class="rounded-2xl border bg-white p-5 shadow-sm">
    <div class="flex items-center justify-between">
      <h2 class="font-semibold text-slate-900">サマリー</h2>
      <div class="text-xs text-slate-500">入力中は即時更新</div>
    </div>

    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-xl border bg-slate-50 p-4">
        <div class="text-sm font-semibold text-slate-800 mb-2">カテゴリ別合計</div>
        <ul id="category-totals" class="space-y-1 text-sm text-slate-700"></ul>
        <p class="mt-2 text-xs text-slate-500">※カテゴリは入力内容で集計されます（表記ゆれ注意）</p>
      </div>

      <div class="rounded-xl border bg-blue-50 p-4">
        <div class="text-sm font-semibold text-blue-900 mb-2">合計時間</div>
        <div class="text-3xl font-extrabold text-blue-900">
          <span id="total-hours">--</span><span class="text-base font-semibold text-blue-800 ml-1">h</span>
        </div>
         {{-- ひとことコメント --}}
        @if (session('toast_message'))
        <div
            id="toast-message"
            class="mt-3 rounded-lg border border-blue-200 bg-white/70 px-3 py-2 text-sm text-slate-800"
        >
            {{ session('toast_message') }}
        </div>
        @endif
        <div class="mt-2 text-xs text-blue-900/70">
          入力ミス防止のため、1行あたり最大20時間までです。
        </div>
      </div>
    </div>
  </div>

  {{-- Template (for JS add-row) --}}
  <template id="task-row-template">
    <div data-row class="rounded-xl border bg-slate-50 p-4">
      <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
        <div class="md:col-span-4">
          <label class="block text-xs font-medium text-slate-600">カテゴリ（自由入力OK）</label>
          <input
            type="text"
            name="rows[__INDEX__][category]"
            list="category-options"
            placeholder="例：開発 / 調査 / 保守 / 会議"
            class="mt-1 w-full rounded-lg border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100"
          >
        </div>

        <div class="md:col-span-3">
          <label class="block text-xs font-medium text-slate-600">開始</label>
          <div class="mt-1 flex items-center gap-2">
            <select name="rows_ui[__INDEX__][start_h]" data-time-select
              class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
              <option value="">--</option>
              <option value="00">00</option><option value="01">01</option><option value="02">02</option><option value="03">03</option>
              <option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option>
              <option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option>
              <option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option>
              <option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option>
              <option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option>
            </select>
            <span class="text-slate-400">:</span>
            <select name="rows_ui[__INDEX__][start_m]" data-time-select
              class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
              <option value="">--</option>
              <option value="00">00</option>
              <option value="15">15</option>
              <option value="30">30</option>
              <option value="45">45</option>
            </select>
          </div>
        </div>

        <div class="md:col-span-3">
          <label class="block text-xs font-medium text-slate-600">終了</label>
          <div class="mt-1 flex items-center gap-2">
            <select name="rows_ui[__INDEX__][end_h]" data-time-select
              class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
              <option value="">--</option>
              <option value="00">00</option><option value="01">01</option><option value="02">02</option><option value="03">03</option>
              <option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option>
              <option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option>
              <option value="12">12</option><option value="13">13</option><option value="14">14</option><option value="15">15</option>
              <option value="16">16</option><option value="17">17</option><option value="18">18</option><option value="19">19</option>
              <option value="20">20</option><option value="21">21</option><option value="22">22</option><option value="23">23</option>
            </select>
            <span class="text-slate-400">:</span>
            <select name="rows_ui[__INDEX__][end_m]" data-time-select
              class="w-24 rounded-lg border-slate-200 bg-white px-2 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
              <option value="">--</option>
              <option value="00">00</option>
              <option value="15">15</option>
              <option value="30">30</option>
              <option value="45">45</option>
            </select>
          </div>
        </div>

        <input type="hidden" name="rows[__INDEX__][start]" value="">
        <input type="hidden" name="rows[__INDEX__][end]" value="">

        <div class="md:col-span-1 text-center">
          <div class="text-xs text-slate-500">時間</div>
          <div class="mt-1 text-lg font-bold text-slate-900 task-duration">
            <strong>--</strong><span class="text-sm font-semibold text-slate-500">h</span>
          </div>
        </div>

        <div class="md:col-span-1 flex md:flex-col gap-2 justify-end">
          <button type="button" data-clear-row
            class="rounded-lg bg-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-300">
            クリア
          </button>
          <button type="button" data-remove
            class="rounded-lg bg-red-100 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-200">
            削除
          </button>
        </div>
      </div>
    </div>
  </template>

  {{-- JS --}}
  @vite('resources/js/timecalc.js')
</div>
@endsection