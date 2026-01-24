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
     * ・※現在は使用してない ルートコメントアウト済み
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
     * 非同期（AJAX）用：打刻計算処理
     *
     * 役割：
     * - JSから送られてきた rows を最終チェックする
     * - 計算結果（行ごとの時間・カテゴリ別合計・合計時間）を返す
     * - 成功時は「一言コメント」も生成して返す
     *
     * 方針：
     * - JS即時計算が主役
     * - サーバーは「事故防止の最終保証」
     * - 失敗時は 422 + errors を返す（画面遷移なし）
     */
    public function calculateJson(Request $request)
    {
        $rows = $request->input('rows', []);

        // 空行除外 + index詰め
        $filledRows = collect($rows)
            ->filter(function ($row) {
                $category = $row['category'] ?? null;
                $start    = $row['start'] ?? null;
                $end      = $row['end'] ?? null;
                return !empty($category) || !empty($start) || !empty($end);
            })
            ->values()
            ->all();

        if (count($filledRows) === 0) {
            return response()->json([
                'ok' => false,
                'message' => '1つ以上入力してください。',
                'errors' => ['rows' => ['1つ以上入力してください。']],
            ], 422);
        }

        // 基本バリデーション
        $validator = \Illuminate\Support\Facades\Validator::make(
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

        // 追加ルール
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
                    $v->errors()->add("rows.$i.end", '0時間（開始＝終了）は不可です。');
                    continue;
                }

                $diffMinutes = $this->diffMinutesAllowOvernight($start, $end);

                if ($diffMinutes % self::STEP_MINUTES !== 0) {
                    $v->errors()->add("rows.$i.end", '開始・終了は15分刻みで入力してください。');
                }

                if ($diffMinutes > self::MAX_HOURS_PER_ROW * 60) {
                    $v->errors()->add("rows.$i.end", '勤務時間が長すぎます（1行あたり20時間以内）。');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => '入力エラーがあります。',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 計算 + 集計
        $rowHours = [];
        $categoryTotals = [];
        $totalHours = 0.0;
        $hasOvernight = false;

        foreach ($filledRows as $i => $row) {
            $category = $this->normalizeCategory($row['category'] ?? '');
            $start = $row['start'];
            $end   = $row['end'];

            if ($this->isOvernight($start, $end)) $hasOvernight = true;

            $minutes = $this->diffMinutesAllowOvernight($start, $end);
            $quarters = intdiv($minutes, self::STEP_MINUTES);
            $hours = $quarters * 0.25;

            $rowHours[$i] = $hours;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $hours;
            $totalHours += $hours;
        }

        $toast = $this->buildToastMessage($totalHours, $hasOvernight);

        return response()->json([
            'ok' => true,
            'rowHours' => $rowHours,
            'categoryTotals' => $categoryTotals,
            'totalHours' => $totalHours,
            'toast' => $toast,
        ]);
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

            // NARUTO
            "「まっすぐ自分の言葉は曲げねぇ…それが俺の忍道だ！」（うずまきナルト）🔥(ง •̀_•́)ง",
            "「仲間を大切にしない奴は、それ以上のクズだ」（はたけカカシ）( ˘ω˘ )",

            // BLEACH
            "「憧れは、理解から最も遠い感情だよ」（藍染惣右介）😏",
            "「俺が天に立つ」（黒崎一護）⚡(｀・ω・´)",

            // HUNTER×HUNTER
            "「それって…悪手だよね？」（キルア）😈",
            "「感謝するぜ。お前と出会えたこれまでの全てに！」（ネテロ）🙏",
            "「いいねェ……ゾクゾクする♥」（ヒソカ）😈♠",
            "「壊したくなっちゃうなぁ♥」（ヒソカ）🔪😏",

            // STEINS;GATE
            "「エル・プサイ・コングルゥ」（岡部倫太郎）🧠✨",
            "「運命石の扉（シュタインズ・ゲート）を選ぶ」（岡部）( ✧Д✧)",

            // コードギアス
            "「撃っていいのは、撃たれる覚悟のある奴だけだ」（ルルーシュ）♟️😐",
            "「我に従え！」（ルルーシュ）( ✧Д✧)☝",

            // 東京喰種
            "「この世のすべての不利益は当人の能力不足」（金木研）🩸( ˘ω˘ )",
            "「僕は喰種だ」（金木研）😈",

            // Re:ゼロ
            "「俺は必ず、お前を救ってみせる！」（ナツキ・スバル）(｀；ω；´)🔥",
            "「立って。立ってよ、スバルくん」（エミリア）( ˘ω˘ )",

            // ソードアート・オンライン
            "「これはゲームじゃない…現実だ」（キリト）⚔️",
            "「俺はソロだ」（キリト）😐",

            // エヴァンゲリオン
            "「逃げちゃダメだ…逃げちゃダメだ…」（碇シンジ）😵",

            // 鋼の錬金術師
            "「立って歩け。前へ進め」（エドワード・エルリック）🔥",
            "「等価交換だ」（エド）( ˘ω˘ )b",

            // Fate
            "「―――問おう。貴方が、私のマスターか」（セイバー）⚔️✨",
            "「正義の味方を貫く」（衛宮士郎）(｀・ω・´)",

            // ONE PIECE
            "「海賊王に、俺はなる！！」（モンキー・D・ルフィ）🔥(ง •̀_•́)ง",
            "「おれは助けてもらわねェと生きていけねェ自信がある！！」（ルフィ）(｀；ω；´)",
            "「何が嫌いかより何が好きかで自分を語れよ！」（ルフィ）☝(｀・ω・´)",

            // 鬼滅の刃
            "「お前も…鬼にならないか？」（鬼舞辻無惨）😈( ˘ω˘ )",
            "「心を燃やせ」（煉獄杏寿郎）🔥🔥(๑•̀ㅂ•́)و✧",
            "「俺は俺の責務を全うする！！」（煉獄）(｀・ω・´)ゞ",
            "「もうダメだァァァ！！！」（善逸）😱⚡",
            // 胡蝶しのぶ（静かに怖い・微笑み圧）
            "「頭を垂れてください。そうすれば…痛みは少ないですよ」（胡蝶しのぶ）😊🔪",
            "「大丈夫ですよ。すぐ終わりますから」（胡蝶しのぶ）🙂💉",
            "「怒ってません。ちょっと…嫌いなだけです」（胡蝶しのぶ）😊☠️",
            // 冨岡義勇（正論・クール）
            "「判断が遅い」（冨岡義勇）⏱️😑",
            "「生殺与奪の権を他人に握らせるな」（冨岡義勇）❄️😐",
            "「俺は嫌われていない」（冨岡義勇）( ˘ω˘ )",

            // 進撃の巨人
            "「それでも俺は進み続ける」（エレン）(ง •̀_•́)ง",
            "「選べ。後悔しない方をだ」（リヴァイ）😐👉",
            "「世界は残酷だ…それでも美しい」（ミカサ）( ˘ω˘ )",

            // ドラゴンボール
            "「ヤムチャさーーーん！！！」（クリリン）😱",
            "「オラ、ワクワクすっぞ！」（孫悟空）(☝ ՞ਊ ՞)☝",
            "「勝ったな…」（ベジータ）😏",
            "「これが…超サイヤ人だ」（悟空）💥(ﾟДﾟ)",

            // 呪術廻戦
            "「大丈夫 僕 最強だから」（五条悟）😎✨",
            "「悪くない判断だ」（五条悟）( ˘ω˘ )b",

            // DEATH NOTE
            "「計画通り」（夜神月）😏📓",
            "「僕は新世界の神になる」（夜神月）( ✧Д✧)",

            // SLAM DUNK
            "「あきらめたら…そこで試合終了ですよ」（安西先生）🏀( ˘ω˘ )",

            // ガンダム
            "「まだだ…まだ終わらんよ」（シャア）😤",
            "「認めたくないものだな…若さゆえの過ちというものを」（シャア）( ´_ゝ`)",

            "合計 {$total}h！今日も偉業だ Σ੧(❛□❛✿)",
            "{$total}h。勤怠に勝った。人類の勝利 ( •̀ ω •́ )✧",
            "今日も何も壊さなかったね。えらい。…ほんとに？ Σ੧(❛□❛✿)",
            "入力ミスの気配ゼロ。プロの匂いがする {$total}h (｀・ω・´)b",
            "{$total}h！勤怠アプリ「助かる」あなた「わかる」( ˘ω˘ )",
            "{$total}h！“過労キティ( ´∀｀)",
            "{$total}h…これは堅実。堅実は最強 ( ˘ω˘ )",
            "{$total}h！今日も“未然に防ぐ”ができてる (｀・ω・´)",
            "勤怠って結局…気合。{$total}h、気合 ( •̀ ω •́ )✧",
            "{$total}h。偉いので休憩していい (｀・∀・´)b",
            "ここまで綺麗だと逆に怖い {$total}h ( ﾟдﾟ )",
            "{$total}h！そのまま世界を救ってくれ (ง •̀_•́)ง",
            "勤怠入力、成功。…次は人生も成功させよう (｀・ω・´)ゞ",
            "{$total}h。今日は“丁寧な人間”として生きた ( ˘ω˘ )",
            "{$total}h！よし、今日の自分にボーナス (💰じゃなくて( ˘ω˘ ))",
            "{$total}h。証拠は残った。言い逃れ不可 (｀・ω・´)",
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