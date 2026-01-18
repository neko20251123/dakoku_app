<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TimeCalcController extends Controller
{
    /**
     * 1行あたりの最大作業時間（時間）
     * 日付またぎを許可するため、入力ミス（例: 09:00→08:00 = 23h）を防ぐ安全装置
     */
    private const MAX_HOURS_PER_ROW = 20;

    /**
     * 入力刻み（分）
     * 15分刻み = 0.25h単位で計算
     */
    private const STEP_MINUTES = 15;

    /**
     * メイン画面表示
     * フォーム入力画面を表示するだけ
     */
    public function index()
    {
        return view('timecalc.index');
    }

    /**
     * 計算処理（サーバー側の最終チェック）
     *
     * ・JSの即時計算とは別に、サーバーで必ず正当性を保証する
     * ・バリデーションエラー時は入力値を保持して元画面へ戻す
     * ・正常時は計算結果を session('result') に載せて元画面へ戻す
     */
    public function calculate(Request $request)
    {
        // rows は hidden の start/end（HH:MM形式）で送られてくる想定
        $rows = $request->input('rows', []);

        /**
         * 空行の除外
         * category / start / end のいずれも空の行は無視する
         * values() で index を 0,1,2... に詰め直す（表示ズレ防止）
         */
        $filledRows = collect($rows)
            ->filter(function ($row) {
                $category = $row['category'] ?? null;
                $start    = $row['start'] ?? null;
                $end      = $row['end'] ?? null;

                return !empty($category) || !empty($start) || !empty($end);
            })
            ->values()
            ->all();

        /**
         * 有効な入力行が1つもない場合はエラー
         */
        if (count($filledRows) === 0) {
            return back()
                ->withErrors(['rows' => '1つ以上入力してください。'])
                ->withInput();
        }

        /**
         * 基本バリデーション
         * ・各行で category / start / end を必須
         * ・時刻は HH:MM 形式
         */
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

        /**
         * 業務ルールに基づく追加バリデーション
         * ・15分刻みのみ許可
         * ・0時間（開始＝終了）禁止
         * ・夜間またぎは許可
         * ・1行あたり最大20時間まで（入力ミス防止）
         */
        $validator->after(function ($v) use ($filledRows) {
            foreach ($filledRows as $i => $row) {
                $start = $row['start'] ?? null;
                $end   = $row['end'] ?? null;

                // 基本バリデーションで弾かれる想定だが、念のため
                if (!$start || !$end) {
                    continue;
                }

                // 15分刻みチェック
                if (!$this->isQuarterTime($start)) {
                    $v->errors()->add(
                        "rows.$i.start",
                        '開始時刻は15分刻み（00/15/30/45）で入力してください。'
                    );
                }

                if (!$this->isQuarterTime($end)) {
                    $v->errors()->add(
                        "rows.$i.end",
                        '終了時刻は15分刻み（00/15/30/45）で入力してください。'
                    );
                }

                // 0時間は禁止
                if ($start === $end) {
                    $v->errors()->add(
                        "rows.$i.end",
                        '終了時刻は開始時刻と同じにできません（0時間は不可）。'
                    );
                    continue;
                }

                // 夜間またぎを考慮して差分（分）を計算
                $diffMinutes = $this->diffMinutesAllowOvernight($start, $end);

                // 念のため：15分で割り切れない場合はエラー
                if ($diffMinutes % self::STEP_MINUTES !== 0) {
                    $v->errors()->add(
                        "rows.$i.end",
                        '開始・終了は15分刻みで入力してください。'
                    );
                }

                // 最大作業時間チェック（入力ミス防止）
                if ($diffMinutes > self::MAX_HOURS_PER_ROW * 60) {
                    $v->errors()->add(
                        "rows.$i.end",
                        '勤務時間が長すぎます（1行あたり20時間以内にしてください）。入力ミスの可能性があります。'
                    );
                }
            }
        });

        /**
         * バリデーションエラー時は元画面へ戻す
         */
        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        /**
         * 計算処理
         * ・差分（分）を 15分単位に分解
         * ・0.25h単位で時間を算出
         * ・行ごと / カテゴリ別 / 合計を集計
         */

        $rowHours = [];
        $categoryTotals = [];
        $totalHours = 0.0;

        foreach ($filledRows as $i => $row) {
            $category = $this->normalizeCategory($row['category'] ?? '');            $start    = $row['start'];
            $end      = $row['end'];

            // 夜間またぎを考慮した差分（分）
            $minutes = $this->diffMinutesAllowOvernight($start, $end);

            // 15分単位 → 時間（0.25h）
            $quarters = intdiv($minutes, self::STEP_MINUTES);
            $hours = $quarters * 0.25;

            $rowHours[$i] = $hours;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $hours;
            $totalHours += $hours;
        }

        /**
         * 計算結果を session に載せて元画面へ戻す
         * JS即時計算が主役だが、サーバーは最終保証の役割
         */
        return back()
            ->withInput()
            ->with('result', [
                'rowHours' => $rowHours,
                'categoryTotals' => $categoryTotals,
                'totalHours' => $totalHours,
                'maxHoursPerRow' => self::MAX_HOURS_PER_ROW,
                'stepMinutes' => self::STEP_MINUTES,
            ]);
    }

    /**
     * 指定された時刻が15分刻みかを判定する
     *
     * @param string $time "HH:MM"
     * @return bool true: 00/15/30/45 のいずれか
     */
    private function isQuarterTime(string $time): bool
    {
        $parts = explode(':', $time);
        if (count($parts) !== 2) {
            return false;
        }

        $mm = (int) $parts[1];
        return in_array($mm, [0, 15, 30, 45], true);
    }

    /**
     * 開始・終了時刻の差分（分）を計算する（夜間またぎ対応）
     *
     * 例:
     * ・20:00 → 07:00 = 11時間（660分）
     * ・09:00 → 18:00 = 9時間（540分）
     */
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

    /**
     * カテゴリ文字列を正規化して集計の表記ゆれを防ぐ
     * - 前後空白を削除
     * - 連続空白を1つに
     */
    private function normalizeCategory(string $category): string
    {
        $category = trim($category);
        $category = preg_replace('/\s+/u', ' ', $category) ?? $category;
        return $category;
    }
}