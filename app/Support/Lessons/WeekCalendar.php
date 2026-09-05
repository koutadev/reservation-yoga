<?php

namespace App\Support\Lessons;

use App\Models\LessonSlot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 空き枠カレンダー（週表示）の組み立て。
 *
 * 取得済みの枠を「開始時刻の行 × 日〜土の列」に並べ替えるだけで、DB は見ない。
 * 行は実際に開講がある時刻だけを作るので、1 週間がスクロールなしに収まる
 * （固定の時間軸を上から下まで敷き詰めない）。
 *
 * 同じ日・同じ時刻に複数のレッスンがある場合は、そのセルに複数の枠が入り、
 * 画面では上下に積んで表示する（DEC-014）。
 *
 * MVP は週表示のみ。月／日表示は次イテレーション（DEC-014）。
 */
class WeekCalendar
{
    private const DAYS_IN_WEEK = 7;

    /**
     * @param  Collection<int, LessonSlot>  $slots  この週の枠（開始時刻の昇順）
     */
    private function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        private readonly Collection $slots,
    ) {}

    /**
     * @param  Collection<int, LessonSlot>  $slots
     */
    public static function build(Carbon $weekStart, Collection $slots): self
    {
        $start = $weekStart->copy()->startOfDay();

        return new self(
            start: $start,
            end: $start->copy()->addDays(self::DAYS_IN_WEEK - 1)->endOfDay(),
            slots: $slots->sortBy([['starts_at', 'asc'], ['id', 'asc']])->values(),
        );
    }

    /**
     * 日〜土の 7 日分。
     *
     * @return list<Carbon>
     */
    public function days(): array
    {
        return array_map(
            fn (int $offset): Carbon => $this->start->copy()->addDays($offset),
            range(0, self::DAYS_IN_WEEK - 1),
        );
    }

    /**
     * 時刻ごとの行。開講のある時刻だけを、早い順に並べる。
     *
     * cells は日曜から土曜までの 7 要素で、各要素はその日・その時刻の枠の配列
     * （同時間帯に複数あれば 2 件以上入る）。
     *
     * @return list<array{time: string, cells: list<list<LessonSlot>>}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->slots as $slot) {
            $time = $slot->starts_at->format('H:i');
            $column = $this->columnOf($slot->starts_at);

            if ($column === null) {
                continue;
            }

            $rows[$time] ??= array_fill(0, self::DAYS_IN_WEEK, []);
            $rows[$time][$column][] = $slot;
        }

        ksort($rows);

        // 2 つの配列を渡した array_map は、添字を振り直した list を返す
        return array_map(
            static fn (array $cells, string $time): array => ['time' => $time, 'cells' => array_values($cells)],
            $rows,
            array_keys($rows),
        );
    }

    /**
     * その日の枠（スマホの日付切替リストで使う）。
     *
     * @return list<LessonSlot>
     */
    public function slotsOn(Carbon $day): array
    {
        return $this->slots
            ->filter(static fn (LessonSlot $slot): bool => $slot->starts_at->isSameDay($day))
            ->values()
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->slots->isEmpty();
    }

    /**
     * 見出しの期間表示（例: 2026年9月 6 – 12 / 2026年8月30日 – 9月5日）。
     */
    public function rangeLabel(): string
    {
        $last = $this->start->copy()->addDays(self::DAYS_IN_WEEK - 1);

        if ($this->start->isSameMonth($last)) {
            return $this->start->format('Y年n月').' '.$this->start->format('j').' – '.$last->format('j');
        }

        return $this->start->format('Y年n月j日').' – '.$last->format('n月j日');
    }

    /**
     * 週の何列目か（日曜=0 … 土曜=6）。この週の外なら null。
     */
    private function columnOf(Carbon $startsAt): ?int
    {
        $column = (int) $this->start->diffInDays($startsAt->copy()->startOfDay(), false);

        return $column >= 0 && $column < self::DAYS_IN_WEEK ? $column : null;
    }
}
