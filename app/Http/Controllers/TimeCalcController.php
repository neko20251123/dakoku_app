<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TimeCalcController extends Controller
{
    private const MAX_HOURS_PER_ROW = 20;
    private const STEP_MINUTES = 15;

    public function index()
    {
        return view('timecalc.index');
    }

    public function calculate(Request $request)
    {
        $rows = $request->input('rows', []);

        // ① 空行を除外 + index詰め（0,1,2..）
        $filledRows = collect($rows)
            ->filter(function ($row) {
                $category = $row['category'] ?? null;
                $start    = $row['start'] ?? null;
                $end      = $row['end'] ?? null;

                return !empty($category) || !empty($start) || !empty($end);
            })
            ->values()
            ->all();

        // ② 1行も入力がなければエラー
        if (count($filledRows) === 0) {
            return back()
                ->withErrors(['rows' => '1つ以上入力してください。'])
                ->withInput();
        }

        // ③ 基本バリデーション
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

        // ④ 追加仕様（15分刻み / 0時間NG / 夜間またぎOK / 20時間上限）
        $validator->after(function ($v) use ($filledRows) {
            foreach ($filledRows as $i => $row) {
                $start = $row['start'] ?? null;
                $end   = $row['end'] ?? null;

                if (!$start || !$end) continue;

                if (!$this->isQuarterTime($start)) {
                    $v->errors()->add("rows.$i.start", '開始時刻は15分刻み（00/15/30/45）で入力してください。');
                }
                if (!$this->isQuarterTime($end)) {
                    $v->errors()->add("rows.$i.end", '終了時刻は15分刻み（00/15/30/45）で入力してください。');
                }

                if ($start === $end) {
                    $v->errors()->add("rows.$i.end", '終了時刻は開始時刻と同じにできません（0時間は不可）。');
                    continue;
                }

                $diffMinutes = $this->diffMinutesAllowOvernight($start, $end);

                if ($diffMinutes % self::STEP_MINUTES !== 0) {
                    $v->errors()->add("rows.$i.end", '開始・終了は15分刻みで入力してください。');
                }

                if ($diffMinutes > self::MAX_HOURS_PER_ROW * 60) {
                    $v->errors()->add(
                        "rows.$i.end",
                        '勤務時間が長すぎます（1行あたり20時間以内にしてください）。入力ミスの可能性があります。'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // ⑤ 計算（0.25h単位）+ 集計
        $categoryLabels = [
            'task1' => 'タスク1',
            'task2' => 'タスク2',
            'task3' => 'タスク3',
        ];

        $rowHours = [];
        $categoryTotals = [];
        $totalHours = 0.0;

        foreach ($filledRows as $i => $row) {
            $category = $row['category'];
            $start    = $row['start'];
            $end      = $row['end'];

            $minutes = $this->diffMinutesAllowOvernight($start, $end);

            $quarters = intdiv($minutes, self::STEP_MINUTES);
            $hours = $quarters * 0.25;

            $rowHours[$i] = $hours;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $hours;
            $totalHours += $hours;
        }

        // ★忘れてた return（ここが超重要）
        return back()
            ->withInput()
            ->with('result', [
                'rowHours' => $rowHours,
                'categoryTotals' => $categoryTotals,
                'totalHours' => $totalHours,
                'categoryLabels' => $categoryLabels,
                'maxHoursPerRow' => self::MAX_HOURS_PER_ROW,
                'stepMinutes' => self::STEP_MINUTES,
            ]);
    }

    private function isQuarterTime(string $time): bool
    {
        $parts = explode(':', $time);
        if (count($parts) !== 2) return false;

        $mm = (int) $parts[1];
        return in_array($mm, [0, 15, 30, 45], true);
    }

    private function diffMinutesAllowOvernight(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        $startMin = $sh * 60 + $sm;
        $endMin   = $eh * 60 + $em;

        if ($endMin < $startMin) {
            $endMin += 24 * 60;
        }

        return $endMin - $startMin;
    }
}