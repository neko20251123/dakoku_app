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
     */
    public function index()
    {
        return view('timecalc.index');
    }

    /**
     * 計算処理（サーバー側の最終チェック）
     *
     * ・JS即時計算とは別に、サーバーで必ず正当性を保証する
     * ・バリデーションエラー時は入力値を保持して元画面へ戻す
     * ・正常時は計算結果を session('result') に載せて元画面へ戻す
     */
    public function calculate(Request $request)
    {
        // rows は hidden の start/end（HH:MM形式）で送られてくる想定
        $rows = $request->input('rows', []);

        // 空行を除外（category / start / end のいずれも空の行は無視）
        // values() で index を 0,1,2... に詰め直す（結果表示のズレ防止）
        $filledRows = collect($rows)
            ->filter(function ($row) {
                $category = $row['category'] ?? null;
                $start    = $row['start'] ?? null;
                $end      = $row['end'] ?? null;

                return !empty($category) || !empty($start) || !empty($end);
            })
            ->values()
            ->all();

        // 有効な入力行が1つもない場合はエラー
        if (count($filledRows) === 0) {
            return back()
                ->withErrors(['rows' => '1つ以上入力してください。'])
                ->withInput();
        }

        // 基本バリデーション（必須 + フォーマット）
        // ・各行で category / start / end を必須
        // ・時刻は HH:MM 形式
        $validator = Validator::make(
            ['rows' => $filledRows],
            [
                'rows' => ['array', 'min:1'],
                'rows.*.category' => ['required', 'string'],
                'rows.*.start'    => ['required', 'date_format:H:i'],
                'rows.*.end'      => ['required', 'date_format:H:i'],
            ],
            [
                'rows.*.category.required' => 'カテゴリを入力してください。',
                'rows.*.start.required'    => '開始時刻を入力してください。',
                'rows.*.end.required'      => '終了時刻を入力してください。',
                'rows.*.start.date_format' => '開始時刻は HH:MM 形式で入力してください。',
                'rows.*.end.date_format'   => '終了時刻は HH:MM 形式で入力してください。',
            ]
        );

        // 追加仕様バリデーション（業務ルール）
        // ・15分刻みのみ許可
        // ・0時間（開始＝終了）禁止
        // ・夜間またぎは許可
        // ・1行あたり最大20時間まで（入力ミス防止）
        $validator->after(function ($v) use ($filledRows) {
            foreach ($filledRows as $i => $row) {
                $start = $row['start'] ?? null;
                $end   = $row['end'] ?? null;

                if (!$start || !$end) {
                    continue;
                }

                // 15分刻みチェック
                if (!$this->isQuarterTime($start)) {
                    $v->errors()->add("rows.$i.start", '開始時刻は15分刻み（00/15/30/45）で入力してください。');
                }
                if (!$this->isQuarterTime($end)) {
                    $v->errors()->add("rows.$i.end", '終了時刻は15分刻み（00/15/30/45）で入力してください。');
                }

                // 0時間は禁止
                if ($start === $end) {
                    $v->errors()->add("rows.$i.end", '終了時刻は開始時刻と同じにできません（0時間は不可）。');
                    continue;
                }

                // 夜間またぎ込みで差分（分）を計算
                $diffMinutes = $this->diffMinutesAllowOvernight($start, $end);

                // 念のため：15分で割り切れない場合はエラー
                if ($diffMinutes % self::STEP_MINUTES !== 0) {
                    $v->errors()->add("rows.$i.end", '開始・終了は15分刻みで入力してください。');
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

        // バリデーションエラー時は元画面へ戻す
        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // 計算（0.25h単位）＋ 集計（カテゴリ別 / 合計）
        $rowHours = [];
        $categoryTotals = [];
        $totalHours = 0.0;

        // 夜間またぎが含まれるか（メッセージ用）
        $hasOvernight = false;

        foreach ($filledRows as $i => $row) {
            $category = $this->normalizeCategory($row['category'] ?? '');
            $start    = $row['start'];
            $end      = $row['end'];

            // 夜間またぎ検知（end < start）
            if ($this->isOvernight($start, $end)) {
                $hasOvernight = true;
            }

            $minutes = $this->diffMinutesAllowOvernight($start, $end);

            // 15分単位 → 時間（0.25h）
            $quarters = intdiv($minutes, self::STEP_MINUTES);
            $hours = $quarters * 0.25;

            $rowHours[$i] = $hours;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $hours;
            $totalHours += $hours;
        }

        // 成功時の一言（30種＋夜勤専用）
        $toastMessage = $this->buildToastMessage($totalHours, $hasOvernight);

        // 計算結果を session に載せて元画面へ戻す（サーバ側の最終保証）
        return back()
            ->withInput()
            ->with('result', [
                'rowHours' => $rowHours,
                'categoryTotals' => $categoryTotals,
                'totalHours' => $totalHours,
                'maxHoursPerRow' => self::MAX_HOURS_PER_ROW,
                'stepMinutes' => self::STEP_MINUTES,
            ])
            ->with('toast_message', $toastMessage);
    }

    /**
     * カテゴリ文字列を正規化して表記ゆれを減らす
     * - 前後空白を削除
     * - 連続空白を1つに
     */
    private function normalizeCategory(string $category): string
    {
        $category = trim($category);
        $category = preg_replace('/\s+/u', ' ', $category) ?? $category;
        return $category;
    }

    /**
     * 指定された時刻が15分刻みかを判定する
     *
     * @param string $time "HH:MM"
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
     * 夜間またぎか判定する（end < start の場合 true）
     */
    private function isOvernight(string $start, string $end): bool
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));

        $startMin = $sh * 60 + $sm;
        $endMin   = $eh * 60 + $em;

        return $endMin < $startMin;
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
     * 成功時の「一言（ランダム）」を生成する
     * - 夜間またぎがあれば専用メッセージを優先
     */
    private function buildToastMessage(float $totalHours, bool $hasOvernight): string
    {
        $total = number_format($totalHours, 2);

       $praiseMessages = [
            "合計 {$total}h！今日も偉業だ Σ੧(❛□❛✿)",
            "{$total}h。勤怠に勝った。人類の勝利 ( •̀ ω •́ )✧",
            "今日も何も壊さなかったね。えらい。…ほんとに？ Σ੧(❛□❛✿)",
            "{$total}h ぶん未来の自分が救われる (ง •̀_•́)ง",
            "入力ミスの気配ゼロ。プロの匂いがする {$total}h (｀・ω・´)b",
            "{$total}h！勤怠アプリ「助かる」あなた「わかる」( ˘ω˘ )",
            "この整然さ…ログ職人の才能 {$total}h ( `･ω･´ )",
            "{$total}h。今日はバグよりあなたが強い (ง🔥Д🔥)ง",
            "よしよし。{$total}h で徳を積んだ (๑•̀ㅂ•́)و✧",
            "{$total}h！“過労キティ( ´∀｀)",
            "あなたの勤怠、平和。{$total}h、平和 ( ˘ω˘ )",
            "{$total}h…これは堅実。堅実は最強 ( ˘ω˘ )",
            "入力が綺麗すぎて泣いた {$total}h (´；ω；`)",
            "この{$total}h、未来の自分が拝むやつ (🙏)",
            "{$total}h！今日も“未然に防ぐ”ができてる (｀・ω・´)",
            "勤怠って結局…気合。{$total}h、気合 ( •̀ ω •́ )✧",
            "{$total}h。偉いので休憩していい (｀・∀・´)b",
            "ここまで綺麗だと逆に怖い {$total}h ( ﾟдﾟ )",
            "{$total}h！そのまま世界を救ってくれ (ง •̀_•́)ง",
            "勤怠入力、成功。…次は人生も成功させよう (｀・ω・´)ゞ",
            "{$total}h。今日は“丁寧な人間”として生きた ( ˘ω˘ )",
            "{$total}h！よし、今日の自分にボーナス (💰じゃなくて( ˘ω˘ ))",
            "計算できた？できた。偉い。{$total}h (๑•̀ㅂ•́)و",
            "{$total}h。証拠は残った。言い逃れ不可 (｀・ω・´)",
            "{$total}h！勤怠の神に愛されてる (⛩️)",
            "その{$total}h、ちゃんと“自分の味方”になる (´∀｀)b",
            "{$total}h。うん、今日もちゃんと生きてる (´;ω;`)",
            "{$total}h！ここまで来たらもう優勝 (🏅)",
            "合計 {$total}h。え？もう帰っていいのでは？ ( ﾟдﾟ )",
        ];

        $overnightMessages = [
            "夜勤おつ…！生存しててえらい (´༎ຶོρ༎ຶོ`)",
            "夜間またぎ検知。今日はもう“伝説”扱いでいい (ง •̀_•́)ง",
            "深夜作業…尊敬。水分とってね ( ˘ω˘ )",
            "夜勤モード突入…！勤怠より先に体を守れ (｀・ω・´)ゞ",
            "この時間に{$total}h？それはもう勇者 (🗡️)",
            "夜勤おつ。帰ったら寝ろ。いいな？ (｀・ω・´)",
        ];

        if ($hasOvernight) {
            return $overnightMessages[array_rand($overnightMessages)];
        }

        return $praiseMessages[array_rand($praiseMessages)];
    }
}