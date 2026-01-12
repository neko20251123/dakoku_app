<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TimeCalcController extends Controller
{
    public function index()
    {
        return view('timecalc.index');
    }

    public function calculate(Request $request)
    {
        $rows = $request->input('rows', []);

        // ① 空行を除外（indexは保持する：0,1,2...）
        $filledRows = collect($rows)->filter(function ($row) {
            $category = $row['category'] ?? null;
            $start    = $row['start'] ?? null;
            $end      = $row['end'] ?? null;

            return !empty($category) || !empty($start) || !empty($end);
        })->all();

        // ② 1行も入力がなければエラー
        if (count($filledRows) === 0) {
            return back()
                ->withErrors(['rows' => '1つ以上入力してください。'])
                ->withInput();
        }

        // ③ 入力がある行だけ厳密バリデーション
        $validator = Validator::make(
            ['rows' => $filledRows],
            [
                'rows' => ['array', 'min:1'],
                'rows.*.category' => ['required', 'string'],
                'rows.*.start'    => ['required', 'date_format:H:i'],
                'rows.*.end'      => ['required', 'date_format:H:i'],
            ],
            [
                'rows.*.category.required' => 'カテゴリを選択してください。',
                'rows.*.start.required'    => '開始時刻を入力してください。',
                'rows.*.end.required'      => '終了時刻を入力してください。',
                'rows.*.start.date_format' => '開始時刻は HH:MM 形式で入力してください。',
                'rows.*.end.date_format'   => '終了時刻は HH:MM 形式で入力してください。',
            ]
        );

        // ④ 0時間NG / 夜間またぎOK / 20時間超NG
        $validator->after(function ($v) use ($filledRows) {
            foreach ($filledRows as $i => $row) {
                $start = $row['start'] ?? null;
                $end   = $row['end'] ?? null;

                if (!$start || !$end) {
                    continue;
                }

                // 0時間は禁止
                if ($start === $end) {
                    $v->errors()->add("rows.$i.end", '終了時刻は開始時刻より後にしてください。（0時間は不可）');
                    continue;
                }

                // 夜間またぎ含めた差分分数を計算して上限チェック
                $diffMinutes = $this->diffMinutesAllowOvernight($start, $end);

                // 20時間超は禁止（入力ミス防止）
                if ($diffMinutes > 20 * 60) {
                    $v->errors()->add("rows.$i.end", '勤務時間が長すぎます（20時間以内にしてください）。入力ミスの可能性があります。');
                }
            }
        });

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // ⑤ 計算（行別時間 + カテゴリ別合計 + 合計 + コピペテキスト）
        $categoryLabels = [
            'task1' => 'タスク1',
            'task2' => 'タスク2',
            'task3' => 'タスク3',
        ];

        $rowHours = [];         // index => hours
        $categoryTotals = [];   // category => hours
        $totalHours = 0.0;

        foreach ($filledRows as $i => $row) {
            $category = $row['category'];
            $start = $row['start'];
            $end = $row['end'];

            $minutes = $this->diffMinutesAllowOvernight($start, $end);
            $hours = round($minutes / 60, 2);

            $rowHours[$i] = $hours;

            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $hours;
            $totalHours += $hours;
        }

        // コピペ用テキスト生成
        $lines = [];
        foreach ($categoryTotals as $cat => $hours) {
            $label = $categoryLabels[$cat] ?? $cat;
            $lines[] = "{$label}：" . number_format($hours, 2);
        }
        $lines[] = "合計：" . number_format($totalHours, 2);
        $copyText = implode("\n", $lines);

        return back()
            ->withInput()
            ->with('result', [
                'rowHours' => $rowHours,
                'categoryTotals' => $categoryTotals,
                'totalHours' => $totalHours,
                'copyText' => $copyText,
                'categoryLabels' => $categoryLabels,
            ]);
    }

    private function diffMinutesAllowOvernight(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        $startMin = $sh * 60 + $sm;
        $endMin   = $eh * 60 + $em;

        // end < start の場合は翌日扱い
        if ($endMin < $startMin) {
            $endMin += 24 * 60;
        }

        return $endMin - $startMin;
    }
}