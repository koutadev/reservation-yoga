<?php

namespace App\Support\Lessons;

use App\Models\LessonSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 曜日と期間から、レッスン枠をまとめて開講する（STEP2-3）。
 *
 *   「毎週 火・木 の 7:30〜8:15、朝のベーシックヨガ、佐倉、定員 12」を 3 か月ぶん
 *     → 該当する日付ぶんの lesson_slots を個別に作る
 *
 * 作られた枠は独立した 1 件で、あとから 1 枠だけ定員を変えたり中止にしたりできる。
 * シリーズとしての一括編集は行わない（拡張点。必要になったら series_id を足す）。
 *
 * 同じ講師・同じ開始日時の枠がすでにあれば作らない（重複開講の防止）。
 * 二重登録してしまっても、実行し直せば足りない日付だけが埋まる。
 */
final class RecurringSlots
{
    /** 一度に作れる枠の上限（誤操作で大量に作らないための歯止め） */
    public const MAX_SLOTS = 200;

    /**
     * 期間のうち、指定した曜日にあたる日付。
     *
     * @param  list<int>  $weekdays  0(日) 〜 6(土)。Carbon の dayOfWeek と同じ並び
     * @return list<Carbon>
     */
    public static function dates(Carbon $from, Carbon $to, array $weekdays): array
    {
        if ($weekdays === [] || $to->lessThan($from)) {
            return [];
        }

        $dates = [];

        for ($date = $from->copy()->startOfDay(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            if (in_array($date->dayOfWeek, $weekdays, true)) {
                $dates[] = $date->copy();
            }
        }

        return $dates;
    }

    /**
     * 該当する日付ぶんの枠を作る。すでにある枠は飛ばす。
     *
     * @param  array<string, mixed>  $attributes  枠に共通の項目（講師・レッスン名・形式・定員・URL・状態）
     * @param  list<int>  $weekdays  0(日) 〜 6(土)
     * @param  string  $startTime  'H:i'
     * @param  string  $endTime  'H:i'
     */
    public static function generate(
        array $attributes,
        array $weekdays,
        Carbon $from,
        Carbon $to,
        string $startTime,
        string $endTime,
    ): RecurringSlotsResult {
        $dates = self::dates($from, $to, $weekdays);

        if ($dates === []) {
            return new RecurringSlotsResult([], 0);
        }

        $instructorId = (int) $attributes['instructor_id'];

        /** @var list<Carbon> $starts */
        $starts = array_map(
            static fn (Carbon $date): Carbon => $date->copy()->setTimeFromTimeString($startTime),
            $dates,
        );

        return DB::transaction(function () use ($attributes, $instructorId, $starts, $endTime): RecurringSlotsResult {
            // 同じ講師・同じ開始日時の枠を 1 クエリで拾っておき、1 件ずつ問い合わせない
            $taken = LessonSlot::query()
                ->where('instructor_id', $instructorId)
                ->whereIn('starts_at', array_map(
                    static fn (Carbon $start): string => $start->toDateTimeString(),
                    $starts,
                ))
                ->pluck('starts_at')
                ->map(static fn (Carbon $startsAt): string => $startsAt->toDateTimeString())
                ->all();

            $created = [];
            $skipped = 0;

            foreach ($starts as $start) {
                if (in_array($start->toDateTimeString(), $taken, true)) {
                    $skipped++;

                    continue;
                }

                $created[] = LessonSlot::create($attributes + [
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->setTimeFromTimeString($endTime),
                ]);
            }

            return new RecurringSlotsResult($created, $skipped);
        });
    }
}
